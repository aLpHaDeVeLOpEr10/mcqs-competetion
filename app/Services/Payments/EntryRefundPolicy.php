<?php

namespace App\Services\Payments;

use App\Models\Room;
use Illuminate\Support\Str;

/**
 * When a captured entry fee is returned. Refunds are always the full amount
 * charged; a completed competition is never refunded (completed rooms cannot
 * be cancelled, and finished seats are never released).
 *
 *  room_cancelled       any cancellation before valid completion: by staff,
 *                       minimum players not reached, start policy, or the
 *                       engine aborting the competition → always refunded
 *  withdrawn            the player left before the registration deadline →
 *                       refunded unless competition.entry_fees.refund_on_withdrawal is false
 *  reservation_expired  an unconfirmed seat lapsed → refunded if anything was captured
 */
class EntryRefundPolicy
{
    public const ROOM_CANCELLED = 'room_cancelled';

    public const WITHDRAWN = 'withdrawn';

    public const RESERVATION_EXPIRED = 'reservation_expired';

    public function refundable(string $reason): bool
    {
        return match ($reason) {
            self::ROOM_CANCELLED, self::RESERVATION_EXPIRED => true,
            self::WITHDRAWN => (bool) config('competition.entry_fees.refund_on_withdrawal'),
            default => false,
        };
    }

    /**
     * Description recorded on the refund ledger transaction (shown to the player).
     */
    public function describe(Room $room, string $reason): string
    {
        $why = match ($reason) {
            self::ROOM_CANCELLED => 'competition cancelled'.($room->cancellation_reason ? ' ('.$room->cancellation_reason.')' : ''),
            self::WITHDRAWN => 'withdrew before the deadline',
            self::RESERVATION_EXPIRED => 'reservation expired',
            default => $reason,
        };

        return Str::limit($room->name.' — '.$why, 200);
    }
}
