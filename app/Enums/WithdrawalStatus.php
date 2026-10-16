<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * requested → under_review → approved → processing → paid
 *     ↘ cancelled (by the player, before review)
 *     ↘ rejected (by finance, before processing)
 * processing → failed (provider says it definitively failed)
 * paid → reversed (provider reports the money came back)
 *
 * Funds are held (debited) at request time and released exactly once on
 * cancelled, rejected, failed or reversed.
 */
enum WithdrawalStatus: string
{
    use HasValues;

    case Requested = 'requested';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::UnderReview, self::Rejected, self::Cancelled],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved => [self::Processing, self::Rejected],
            self::Processing => [self::Paid, self::Failed],
            self::Paid => [self::Reversed],
            self::Rejected, self::Failed, self::Cancelled, self::Reversed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * States in which the held funds are returned to the player.
     */
    public function releasesFunds(): bool
    {
        return in_array($this, [self::Rejected, self::Failed, self::Cancelled, self::Reversed], true);
    }

    /**
     * Still open: the funds are held and nothing is final.
     *
     * @return list<self>
     */
    public static function pending(): array
    {
        return [self::Requested, self::UnderReview, self::Approved, self::Processing];
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Processing => 'Being paid',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Reversed => 'Returned',
        };
    }
}
