<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * created → pending → processing → succeeded | failed | expired; succeeded → refunded.
 * An expired payment can still become succeeded or failed when the provider later
 * verifies it (the player may have paid just after our deadline).
 */
enum PaymentStatus: string
{
    use HasValues;

    case Created = 'created';
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Starting',
            self::Pending => 'Awaiting payment',
            self::Processing => 'Processing',
            self::Succeeded => 'Successful',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * Still waiting for the provider's outcome.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Created, self::Pending, self::Processing];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * Settled for good: no later callback may change it.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::Refunded], true);
    }
}
