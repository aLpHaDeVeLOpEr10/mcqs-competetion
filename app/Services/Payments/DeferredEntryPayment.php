<?php

namespace App\Services\Payments;

use App\Contracts\Payments\EntryPayment;
use App\Contracts\Payments\EntryPaymentOutcome;
use App\Enums\JoinBlockReason;
use App\Exceptions\AdministrativeActionException;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;

/**
 * Reservation-based entry, for an asynchronous payment flow (e.g. paying a
 * provider per entry): free seats are confirmed at once; paid seats are held
 * as time-limited reservations and are NOT charged until confirmReservation().
 * Not bound by default since Phase 11 (WalletEntryPayment is); kept because the
 * reservation mechanism it drives is tested and may be used by a later flow.
 */
class DeferredEntryPayment implements EntryPayment
{
    public function precheck(Room $room, User $user): ?JoinBlockReason
    {
        return null;
    }

    public function begin(Room $room, RoomParticipant $seat): EntryPaymentOutcome
    {
        if ($room->entry_fee_minor === 0) {
            return EntryPaymentOutcome::confirmed();
        }

        if (! config('competition.paid_entries.enabled')) {
            throw new AdministrativeActionException('Paid entries are disabled.');
        }

        return EntryPaymentOutcome::pending();
    }

    public function release(Room $room, RoomParticipant $seat, string $reason): void
    {
        // No money is captured in this phase, so there is nothing to refund.
    }
}
