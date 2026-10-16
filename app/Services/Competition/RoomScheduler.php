<?php

namespace App\Services\Competition;

use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Models\Room;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Time-driven room transitions, run every minute by `rooms:advance`.
 * Each room is processed in its own transaction holding a row lock, and
 * every step re-checks state, so overlapping or repeated runs are safe.
 */
class RoomScheduler
{
    public function __construct(
        protected RoomLifecycle $lifecycle,
        protected RoomEntryService $entries,
    ) {}

    /**
     * @return array{reservations_expired: int, opened: int, started: int, cancelled: int, errors: int}
     */
    public function advance(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $summary = ['reservations_expired' => 0, 'opened' => 0, 'started' => 0, 'cancelled' => 0, 'errors' => 0];

        $candidates = Room::query()
            ->where(fn ($q) => $q
                // Abandoned reservations
                ->whereIn('id', DB::table('room_participants')->select('room_id')
                    ->where('status', ParticipantStatus::Reserved)->where('reserved_until', '<=', $now))
                // Registration opening
                ->orWhere(fn ($q) => $q->where('status', RoomStatus::Scheduled)
                    ->where(fn ($q) => $q->whereNull('registration_opens_at')->orWhere('registration_opens_at', '<=', $now)))
                // Start time / deadline reached, or full rooms that start when full
                ->orWhere(fn ($q) => $q->whereIn('status', [RoomStatus::Open, RoomStatus::Full])->where(fn ($q) => $q
                    ->where(fn ($q) => $q->whereIn('type', [RoomType::Scheduled, RoomType::Hybrid])->where('scheduled_start_at', '<=', $now))
                    ->orWhere(fn ($q) => $q->where('type', RoomType::Fill)->where('registration_closes_at', '<=', $now))
                    ->orWhere(fn ($q) => $q->whereIn('type', [RoomType::Fill, RoomType::Hybrid])->where('status', RoomStatus::Full)))))
            ->orderBy('id')
            ->pluck('id');

        foreach ($candidates as $roomId) {
            try {
                DB::transaction(function () use ($roomId, $now, &$summary) {
                    $room = Room::query()->lockForUpdate()->find($roomId);
                    if ($room === null) {
                        return;
                    }

                    if (in_array($room->status, [RoomStatus::Open, RoomStatus::Full], true)) {
                        $summary['reservations_expired'] += $this->entries->expireReservationsLocked($room, $now);
                    }

                    if ($this->lifecycle->openRegistrationIfDue($room, $now)) {
                        $summary['opened']++;
                    }

                    match ($this->lifecycle->evaluateStart($room, $now)) {
                        'started' => $summary['started']++,
                        'cancelled' => $summary['cancelled']++,
                        default => null,
                    };
                });
            } catch (Throwable $e) {
                // One bad room must not stop the others; it is retried next minute.
                report($e);
                $summary['errors']++;
            }
        }

        return $summary;
    }
}
