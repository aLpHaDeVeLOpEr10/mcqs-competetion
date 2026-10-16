<?php

namespace App\Services\Payments;

use App\Contracts\Payments\EntryPayment;
use App\Contracts\Payments\EntryPaymentOutcome;
use App\Enums\JoinBlockReason;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\EntryDeniedException;
use App\Exceptions\WalletOperationException;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Wallet\WalletService;

/**
 * Entry fees paid from the player's wallet, synchronously, inside the join
 * transaction (RoomEntryService holds the room lock). Either the seat AND
 * the debit commit together, or neither does.
 *
 * - Free rooms: confirmed with no ledger transaction (nothing was paid, so no
 *   misleading zero-value entry is recorded).
 * - Paid rooms: the seat's frozen entry_fee_minor is debited through
 *   WalletService::chargeEntryFee (idempotency key per seat and join).
 * - release(): refunds the captured fee in full when EntryRefundPolicy says
 *   the reason qualifies. Idempotent: a fee is never refunded twice.
 */
class WalletEntryPayment implements EntryPayment
{
    public function __construct(
        protected WalletService $wallets,
        protected EntryRefundPolicy $refunds,
    ) {}

    public function precheck(Room $room, User $user): ?JoinBlockReason
    {
        return $room->entry_fee_minor > 0 && ($user->wallet?->balance_minor ?? 0) < $room->entry_fee_minor
            ? JoinBlockReason::InsufficientBalance
            : null;
    }

    public function begin(Room $room, RoomParticipant $seat): EntryPaymentOutcome
    {
        if ($seat->entry_fee_minor === 0) {
            return EntryPaymentOutcome::confirmed();
        }

        if (! config('competition.paid_entries.enabled')) {
            throw new AdministrativeActionException('Paid entries are disabled.');
        }

        try {
            $charge = $this->wallets->chargeEntryFee($seat);
        } catch (WalletOperationException $e) {
            // Thrown inside the join transaction: the seat is rolled back with the debit.
            throw new EntryDeniedException(match ($e->reason) {
                WalletOperationException::INSUFFICIENT_FUNDS => JoinBlockReason::InsufficientBalance,
                WalletOperationException::ACCOUNT_RESTRICTED => JoinBlockReason::AccountRestricted,
                WalletOperationException::DISABLED => JoinBlockReason::PaidEntryUnavailable,
                default => JoinBlockReason::PaymentFailed,
            });
        }

        return EntryPaymentOutcome::confirmed($charge->reference);
    }

    public function release(Room $room, RoomParticipant $seat, string $reason): void
    {
        if ($seat->entry_ledger_transaction_id === null || ! $this->refunds->refundable($reason)) {
            return;
        }

        $this->wallets->refundEntryFee($seat, $this->refunds->describe($room, $reason));
    }
}
