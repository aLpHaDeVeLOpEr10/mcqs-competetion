<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * pending → (approved, when review is required) → processing → paid
 * held: blocked (payouts disabled, open disputes, account review, manual hold)
 * failed: the credit was refused; retried only by staff
 * reversed: a paid prize taken back by a ledger reversal (final)
 * cancelled: the entitlement disappeared after a results correction (final)
 */
enum PayoutStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Held = 'held';
    case Failed = 'failed';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Held => 'On hold',
            self::Failed => 'Failed',
            self::Reversed => 'Reversed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Entitlements still waiting to be paid.
     *
     * @return list<self>
     */
    public static function outstanding(): array
    {
        return [self::Pending, self::Approved, self::Processing, self::Held, self::Failed];
    }

    /**
     * May still be changed by a results recalculation (nothing has moved yet).
     *
     * @return list<self>
     */
    public static function recalculable(): array
    {
        return [self::Pending, self::Approved, self::Held, self::Failed];
    }
}
