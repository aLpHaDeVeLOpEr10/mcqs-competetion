<?php

namespace App\Services\Competition;

use App\Contracts\Payments\EntryPayment;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Enums\UnderCapacityPolicy;
use App\Events\Competition\CompetitionCompleted;
use App\Events\Rooms\RoomOccupancyChanged;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\ContentValidationException;
use App\Models\CompetitionSession;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Content\PaperSnapshotService;
use App\Services\Engine\CompetitionWaker;
use App\Services\Engine\EngineBroadcaster;
use App\Services\Payments\EntryRefundPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The room state machine. Every status change goes through transition(),
 * which enforces RoomStatus::allowedTransitions() and writes an audit
 * record. Methods taking a "locked" room must be called inside a
 * transaction that holds SELECT ... FOR UPDATE on that room.
 *
 * Starting rules (evaluated by evaluateStart):
 *  - startsWhenFull rooms (fill/hybrid) start once every seat is confirmed,
 *    after countdown_seconds.
 *  - Rooms with a scheduled start reach it: start if full, or if the
 *    under-capacity policy allows and the minimum is met; otherwise cancel.
 *  - Fill rooms reach their registration deadline unfilled: same rule.
 * Moving from "starting" to "in progress" belongs to the competition engine.
 */
class RoomLifecycle
{
    public function __construct(
        protected AuditLogger $audit,
        protected RoomConfigValidator $validator,
        protected PaperSnapshotService $snapshots,
        protected EntryPayment $payments,
    ) {}

    public function publish(User $actor, Room $room): Room
    {
        return DB::transaction(function () use ($actor, $room) {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);

            if (! $room->isDraft()) {
                throw new AdministrativeActionException('Only draft rooms can be published.');
            }

            if ($problems = $this->validator->problems($room)) {
                throw new ContentValidationException('The room cannot be published yet.', $problems);
            }

            // Freeze the questions, options, timings and answer key now.
            $session = $this->snapshots->snapshot($room, $actor);

            $room->published_at = now();
            $opensLater = $room->registration_opens_at?->isFuture() ?? false;
            $this->transition($room, $opensLater ? RoomStatus::Scheduled : RoomStatus::Open, $actor, 'Published', [
                'competition_session_id' => $session->id,
            ]);

            return $room;
        });
    }

    public function cancel(Room $room, ?User $actor, string $reason): Room
    {
        return DB::transaction(function () use ($room, $actor, $reason) {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);
            $this->cancelLocked($room, $actor, $reason);

            return $room;
        });
    }

    /**
     * Cancel a locked room: release every seat through the entry payment
     * (refunds happen there in the wallet phase). Seat rows are kept as history.
     */
    public function cancelLocked(Room $room, ?User $actor, string $reason): void
    {
        if (! $room->status->canTransitionTo(RoomStatus::Cancelled)) {
            throw new AdministrativeActionException("A {$room->status->label()} room cannot be cancelled.");
        }

        $seats = RoomParticipant::query()
            ->where('room_id', $room->id)
            ->whereIn('status', [ParticipantStatus::Reserved, ParticipantStatus::Joined, ParticipantStatus::Playing])
            ->lockForUpdate()
            ->get();

        // Set first so refund descriptions can quote it.
        $room->forceFill(['cancelled_at' => now(), 'cancellation_reason' => $reason]);
        $refunded = 0;

        foreach ($seats as $seat) {
            // Refunds any captured entry fee in full (idempotent; see EntryRefundPolicy).
            $this->payments->release($room, $seat, EntryRefundPolicy::ROOM_CANCELLED);
            $seat->refresh();

            if ($seat->status === ParticipantStatus::Reserved) {
                $seat->forceFill(['status' => ParticipantStatus::Expired, 'expired_at' => now(), 'reserved_until' => null])->save();
            } elseif ($seat->refund_ledger_transaction_id !== null && $seat->status->canTransitionTo(ParticipantStatus::Refunded)) {
                $seat->forceFill(['status' => ParticipantStatus::Refunded])->save();
                $refunded++;
            }
        }

        $this->transition($room, RoomStatus::Cancelled, $actor, $reason, ['seats_released' => $seats->count()]);

        // Every captured fee was returned in this same transaction.
        if ($refunded > 0) {
            $this->transition($room, RoomStatus::Refunded, $actor, 'Entry fees refunded', ['seats_refunded' => $refunded]);
        }

        // Stop any live session; players are told once the cancellation commits.
        $session = CompetitionSession::query()->where('room_id', $room->id)->lockForUpdate()->first();
        if ($session !== null && $session->status->canTransitionTo(SessionStatus::Aborted)) {
            $session->forceFill(['status' => SessionStatus::Aborted, 'aborted_at' => now(), 'abort_reason' => mb_substr($reason, 0, 255)])->save();
            $this->audit->record('competition.aborted', $session, null, ['reason' => $reason], actor: $actor);
            DB::afterCommit(fn () => app(EngineBroadcaster::class)->send([new CompetitionCompleted($session)]));
        }
    }

    /**
     * Scheduled rooms open for registration when their opening time arrives.
     */
    public function openRegistrationIfDue(Room $room, CarbonInterface $now): bool
    {
        if ($room->status !== RoomStatus::Scheduled || ($room->registration_opens_at && $room->registration_opens_at->gt($now))) {
            return false;
        }

        $this->transition($room, RoomStatus::Open, null, 'Registration opened');

        return true;
    }

    /**
     * Apply the start policy to a locked open/full room.
     *
     * @return 'started'|'cancelled'|null
     */
    public function evaluateStart(Room $room, CarbonInterface $now): ?string
    {
        if (! in_array($room->status, [RoomStatus::Open, RoomStatus::Full], true)) {
            return null;
        }

        $counts = RoomParticipant::query()->where('room_id', $room->id)
            ->selectRaw("SUM(status = 'joined') as confirmed, SUM(status = 'reserved') as reserved")
            ->first();
        $confirmed = (int) $counts->confirmed;
        $reserved = (int) $counts->reserved;
        $full = $confirmed >= $room->max_participants;

        // Every seat confirmed and the policy starts on fill.
        if ($room->type->startsWhenFull() && $full && $reserved === 0) {
            $this->triggerStart($room, $now->copy()->addSeconds($room->countdown_seconds), 'All seats filled', $confirmed);

            return 'started';
        }

        $deadlineReached = $room->type->hasScheduledStart()
            ? ($room->scheduled_start_at !== null && $room->scheduled_start_at->lte($now))
            : ($room->registration_closes_at !== null && $room->registration_closes_at->lte($now));

        if (! $deadlineReached) {
            return null;
        }

        $enough = $full || ($room->under_capacity_policy === UnderCapacityPolicy::StartIfMinimum && $confirmed >= $room->min_participants);

        if (! $enough) {
            $this->cancelLocked($room, null, "Not enough players: {$confirmed} confirmed, {$room->min_participants} required.");

            return 'cancelled';
        }

        // Unconfirmed reservations cannot take part once the competition starts.
        app(RoomEntryService::class)->expireReservationsLocked($room, $now, force: true);
        $this->triggerStart($room, $room->type->hasScheduledStart() ? $now : $now->copy()->addSeconds($room->countdown_seconds), 'Start time reached', $confirmed);

        return 'started';
    }

    public function transition(Room $room, RoomStatus $to, ?User $actor, ?string $reason = null, array $metadata = []): void
    {
        $from = $room->status;

        if (! $from->canTransitionTo($to)) {
            throw new AdministrativeActionException("A room cannot move from {$from->value} to {$to->value}.");
        }

        $room->forceFill(['status' => $to, 'lock_version' => $room->lock_version + 1])->save();
        RoomOccupancyChanged::afterCommit($room->id);

        $this->audit->record('room.status_changed', $room,
            ['status' => $from->value],
            ['status' => $to->value],
            array_filter(['reason' => $reason] + $metadata, fn ($v) => $v !== null),
            $actor,
        );
    }

    protected function triggerStart(Room $room, CarbonInterface $startsAt, string $reason, int $players): void
    {
        $room->starts_at = $startsAt;
        $this->transition($room, RoomStatus::Starting, null, $reason, [
            'starts_at' => $startsAt->toIso8601String(),
            'players' => $players,
        ]);

        // Hand over to the live engine as soon as this commits (the tick scheduler is the fallback).
        $sessionId = CompetitionSession::query()->where('room_id', $room->id)->value('id');
        if ($sessionId !== null) {
            DB::afterCommit(fn () => app(CompetitionWaker::class)->wake($sessionId));
        }
    }
}
