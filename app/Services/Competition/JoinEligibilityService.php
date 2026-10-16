<?php

namespace App\Services\Competition;

use App\Contracts\Payments\EntryPayment;
use App\Enums\JoinBlockReason;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Decides join eligibility from persisted state only. It never trusts the
 * client and never reports a seat the database has not confirmed.
 */
class JoinEligibilityService
{
    public const JOIN_ROUTE = 'competitions.join';

    public function check(Room $room, ?User $user): JoinEligibility
    {
        return $this->forRooms(collect([$room]), $user)[$room->id];
    }

    /**
     * @param  Collection<int, Room>  $rooms
     * @return array<int, JoinEligibility> keyed by room id
     */
    public function forRooms(Collection $rooms, ?User $user): array
    {
        $seatStatuses = $this->seatStatuses($rooms, $user);

        return $rooms->mapWithKeys(fn (Room $room) => [
            $room->id => $this->evaluate($room, $user, $seatStatuses[$room->id] ?? null),
        ])->all();
    }

    protected function evaluate(Room $room, ?User $user, ?ParticipantStatus $seat): JoinEligibility
    {
        if ($seat === ParticipantStatus::Disqualified) {
            return JoinEligibility::blocked(JoinBlockReason::Disqualified);
        }

        $roomIsOver = in_array($room->status, [RoomStatus::Cancelled, RoomStatus::Refunded, RoomStatus::Completed], true);

        if (in_array($seat, [ParticipantStatus::Joined, ParticipantStatus::Playing, ParticipantStatus::Finished], true) && ! $roomIsOver) {
            return JoinEligibility::blocked(JoinBlockReason::AlreadyJoined);
        }

        if ($seat === ParticipantStatus::Reserved && ! $roomIsOver) {
            return JoinEligibility::blocked(JoinBlockReason::SeatReserved);
        }

        if ($reason = $this->roomStateReason($room)) {
            return JoinEligibility::blocked($reason);
        }

        if ($user === null) {
            return JoinEligibility::blocked(JoinBlockReason::LoginRequired);
        }

        if (! $user->status->canParticipate()) {
            return JoinEligibility::blocked(JoinBlockReason::AccountRestricted);
        }

        if (User::requiresEmailVerification() && ! $user->hasVerifiedEmail()) {
            return JoinEligibility::blocked(JoinBlockReason::EmailNotVerified);
        }

        if (! $room->isFree() && ! config('competition.paid_entries.enabled')) {
            return JoinEligibility::blocked(JoinBlockReason::PaidEntryUnavailable);
        }

        // A hint only (e.g. balance too low): the binding check is the debit itself, under a row lock.
        if (! $room->isFree() && ($reason = app(EntryPayment::class)->precheck($room, $user))) {
            return JoinEligibility::blocked($reason);
        }

        // Defensive: without a join endpoint nobody can join.
        if (! Route::has(self::JOIN_ROUTE)) {
            return JoinEligibility::blocked(JoinBlockReason::JoiningUnavailable);
        }

        return JoinEligibility::allowed();
    }

    protected function roomStateReason(Room $room): ?JoinBlockReason
    {
        $reason = match ($room->status) {
            RoomStatus::Draft, RoomStatus::Scheduled => JoinBlockReason::NotYetOpen,
            RoomStatus::Full => JoinBlockReason::Full,
            RoomStatus::Starting => JoinBlockReason::Starting,
            RoomStatus::InProgress => JoinBlockReason::InProgress,
            RoomStatus::Completed => JoinBlockReason::Ended,
            RoomStatus::Cancelled, RoomStatus::Refunded => JoinBlockReason::Cancelled,
            RoomStatus::Open => null,
        };

        if ($reason !== null) {
            return $reason;
        }

        if ($room->participants_count >= $room->max_participants) {
            return JoinBlockReason::Full;
        }

        if ($room->registration_opens_at?->isFuture()) {
            return JoinBlockReason::NotYetOpen;
        }

        // Explicit cutoff, else the scheduled start.
        if ($room->registrationDeadline()?->isPast()) {
            return JoinBlockReason::RegistrationClosed;
        }

        return null;
    }

    /**
     * The user's seat status per room, in one query.
     *
     * @param  Collection<int, Room>  $rooms
     * @return array<int, ParticipantStatus>
     */
    protected function seatStatuses(Collection $rooms, ?User $user): array
    {
        if ($user === null || $rooms->isEmpty()) {
            return [];
        }

        return RoomParticipant::query()
            ->where('user_id', $user->id)
            ->whereIn('room_id', $rooms->pluck('id'))
            ->get(['room_id', 'status'])
            ->mapWithKeys(fn (RoomParticipant $seat) => [(int) $seat->room_id => $seat->status])
            ->all();
    }
}
