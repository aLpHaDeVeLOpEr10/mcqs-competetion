<?php

namespace App\Services\Competition;

use App\Contracts\Payments\EntryPayment;
use App\Enums\JoinBlockReason;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Events\Rooms\RoomOccupancyChanged;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\EntryDeniedException;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Notifications\RoomJoined;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use App\Services\Payments\EntryRefundPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Atomic room entry. Concurrency safety comes from:
 *  - SELECT ... FOR UPDATE on the room row, serializing every seat change
 *    for that room (join, withdraw, expiry, confirmation, start);
 *  - the unique (room_id, user_id) index: one seat row per user, reused on rejoin;
 *  - the rooms CHECK participants_count <= max_participants as a last line of defence.
 * Repeated joins are idempotent: an existing seat is returned unchanged.
 * All times are taken from the server clock.
 */
class RoomEntryService
{
    public const JOINED = 'joined';

    public const RESERVED = 'reserved';

    public const ALREADY = 'already';

    public function __construct(
        protected JoinEligibilityService $eligibility,
        protected EntryPayment $payments,
        protected RoomLifecycle $lifecycle,
        protected AuditLogger $audit,
    ) {}

    /**
     * @return array{outcome: string, seat: RoomParticipant}
     *
     * @throws EntryDeniedException
     */
    public function join(User $user, Room $room): array
    {
        return DB::transaction(function () use ($user, $room) {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);
            $now = now();

            // Free seats held by abandoned reservations before checking capacity.
            $this->expireReservationsLocked($room, $now);

            $seat = RoomParticipant::query()->where('room_id', $room->id)->where('user_id', $user->id)->lockForUpdate()->first();
            $eligibility = $this->eligibility->check($room, $user);

            if (in_array($eligibility->reason, [JoinBlockReason::AlreadyJoined, JoinBlockReason::SeatReserved], true)) {
                return ['outcome' => self::ALREADY, 'seat' => $seat];
            }

            if ($eligibility->reason !== null) {
                throw new EntryDeniedException($eligibility->reason);
            }

            if ($seat !== null && ! in_array($seat->status, [ParticipantStatus::Withdrawn, ParticipantStatus::Expired], true)) {
                throw new EntryDeniedException(JoinBlockReason::Disqualified);
            }

            if ($room->participants_count >= $room->max_participants) {
                throw new EntryDeniedException(JoinBlockReason::Full);
            }

            $seat ??= (new RoomParticipant)->forceFill(['room_id' => $room->id, 'user_id' => $user->id]);
            $seat->forceFill([
                'status' => ParticipantStatus::Reserved,
                'entry_fee_minor' => $room->entry_fee_minor,
                'joined_at' => $now,
                'reserved_until' => null,
                'confirmed_at' => null,
                'withdrawn_at' => null,
                'expired_at' => null,
                'payment_reference' => null,
            ])->save();

            try {
                $payment = $this->payments->begin($room, $seat);
            } catch (AdministrativeActionException) {
                throw new EntryDeniedException(JoinBlockReason::PaidEntryUnavailable);
            }

            $seat->forceFill($payment->confirmed
                ? ['status' => ParticipantStatus::Joined, 'confirmed_at' => $now, 'payment_reference' => $payment->reference]
                : ['status' => ParticipantStatus::Reserved, 'reserved_until' => $now->copy()->addMinutes((int) config('competition.rooms.reservation_minutes')), 'payment_reference' => $payment->reference]
            )->save();

            $room->increment('participants_count');
            RoomOccupancyChanged::afterCommit($room->id);
            if ($room->participants_count >= $room->max_participants && $room->status === RoomStatus::Open) {
                $this->lifecycle->transition($room, RoomStatus::Full, null, 'Last seat taken');
            }

            $this->audit->record($payment->confirmed ? 'room.seat_joined' : 'room.seat_reserved', $seat, null,
                ['status' => $seat->status->value, 'room_id' => $room->id, 'entry_fee_minor' => $seat->entry_fee_minor, 'entry_reference' => $payment->reference], actor: $user);

            if ($payment->confirmed) {
                app(Notifier::class)->afterCommit($user, new RoomJoined($room, $seat));
                $this->lifecycle->evaluateStart($room, $now);
            }

            return ['outcome' => $payment->confirmed ? self::JOINED : self::RESERVED, 'seat' => $seat];
        });
    }

    /**
     * @throws EntryDeniedException|AdministrativeActionException
     */
    public function withdraw(User $user, Room $room): void
    {
        DB::transaction(function () use ($user, $room) {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);
            $seat = RoomParticipant::query()->where('room_id', $room->id)->where('user_id', $user->id)->lockForUpdate()->first();

            if ($reason = $this->withdrawalBlocker($room, $seat)) {
                throw new AdministrativeActionException($reason);
            }

            $this->payments->release($room, $seat, EntryRefundPolicy::WITHDRAWN);
            $seat->forceFill(['status' => ParticipantStatus::Withdrawn, 'withdrawn_at' => now(), 'reserved_until' => null])->save();
            $this->freeSeat($room, 'Player withdrew');

            $this->audit->record('room.seat_withdrawn', $seat, null, ['room_id' => $room->id], actor: $user);
        });
    }

    /**
     * Why the user cannot withdraw now, or null when they can.
     */
    public function withdrawalBlocker(Room $room, ?RoomParticipant $seat): ?string
    {
        return match (true) {
            $seat === null || ! in_array($seat->status, [ParticipantStatus::Joined, ParticipantStatus::Reserved], true) => 'You do not hold a seat in this room.',
            ! $room->allow_withdrawal => 'This room does not allow withdrawals.',
            ! in_array($room->status, [RoomStatus::Open, RoomStatus::Full], true) => 'Withdrawals are closed for this room.',
            $room->registrationDeadline()?->isPast() ?? false => 'The registration deadline has passed, so withdrawals are closed.',
            default => null,
        };
    }

    /**
     * Called by the wallet phase once a reserved seat's payment settles.
     */
    public function confirmReservation(RoomParticipant $seat, ?string $reference = null): RoomParticipant
    {
        return DB::transaction(function () use ($seat, $reference) {
            $room = Room::query()->lockForUpdate()->findOrFail($seat->room_id);
            $seat = RoomParticipant::query()->lockForUpdate()->findOrFail($seat->id);
            $now = now();

            if ($seat->status !== ParticipantStatus::Reserved || $seat->reserved_until === null || $seat->reserved_until->lte($now)) {
                throw new AdministrativeActionException('This reservation is no longer active.');
            }

            $seat->forceFill(['status' => ParticipantStatus::Joined, 'confirmed_at' => $now, 'reserved_until' => null, 'payment_reference' => $reference ?? $seat->payment_reference])->save();
            $this->audit->record('room.seat_confirmed', $seat, ['status' => 'reserved'], ['status' => 'joined'], ['room_id' => $room->id]);
            app(Notifier::class)->afterCommit(fn () => $seat->user, new RoomJoined($room, $seat));

            $this->lifecycle->evaluateStart($room, $now);

            return $seat;
        });
    }

    /**
     * Expire reservations on a locked room whose hold has lapsed (or all of
     * them when $force is set, e.g. at start time). Returns how many expired.
     */
    public function expireReservationsLocked(Room $room, CarbonInterface $now, bool $force = false): int
    {
        $seats = RoomParticipant::query()
            ->where('room_id', $room->id)
            ->where('status', ParticipantStatus::Reserved)
            ->when(! $force, fn ($q) => $q->where('reserved_until', '<=', $now))
            ->lockForUpdate()
            ->get();

        foreach ($seats as $seat) {
            $this->payments->release($room, $seat, EntryRefundPolicy::RESERVATION_EXPIRED);
            $seat->forceFill(['status' => ParticipantStatus::Expired, 'expired_at' => $now, 'reserved_until' => null])->save();
            $this->freeSeat($room, 'Reservation expired');
            $this->audit->record('room.seat_expired', $seat, ['status' => 'reserved'], ['status' => 'expired'], ['room_id' => $room->id]);
        }

        return $seats->count();
    }

    protected function freeSeat(Room $room, string $reason): void
    {
        $room->decrement('participants_count');
        RoomOccupancyChanged::afterCommit($room->id);

        if ($room->status === RoomStatus::Full && $room->participants_count < $room->max_participants) {
            $this->lifecycle->transition($room, RoomStatus::Open, null, $reason);
        }
    }
}
