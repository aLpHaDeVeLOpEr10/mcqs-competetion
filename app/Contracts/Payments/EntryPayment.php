<?php

namespace App\Contracts\Payments;

use App\Enums\JoinBlockReason;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;

/**
 * Settles a room entry fee. Called by RoomEntryService inside the join
 * transaction, with the room row locked.
 *
 * - begin(): decide how a new seat is paid for. Return Confirmed when no
 *   payment is needed (or it settled synchronously), or Pending to hold the
 *   seat as a time-limited reservation until confirmation arrives.
 * - release(): a seat was given up (withdrawal, expiry, cancellation). Must
 *   be idempotent; the wallet implementation refunds any captured fee here.
 *
 * - precheck(): a cheap, unlocked hint for the room page (e.g. "balance too
 *   low"). Never relied on: begin() makes the binding decision.
 *
 * Implementations must never trust amounts from the client: the fee is
 * always $room->entry_fee_minor, frozen onto the seat.
 */
interface EntryPayment
{
    public function precheck(Room $room, User $user): ?JoinBlockReason;

    public function begin(Room $room, RoomParticipant $seat): EntryPaymentOutcome;

    public function release(Room $room, RoomParticipant $seat, string $reason): void;
}
