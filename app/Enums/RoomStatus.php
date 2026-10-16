<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Registration-side lifecycle of a room. The live game itself is tracked by
 * SessionStatus on the room's competition session.
 */
enum RoomStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Full = 'full';
    case Starting = 'starting';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Open, self::Cancelled],
            self::Scheduled => [self::Open, self::Cancelled],
            self::Open => [self::Full, self::Starting, self::Cancelled],
            self::Full => [self::Open, self::Starting, self::Cancelled],
            self::Starting => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Cancelled => [self::Refunded],
            self::Completed, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function acceptsParticipants(): bool
    {
        return $this === self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'Live',
            self::Starting => 'Starting soon',
            default => ucfirst($this->value),
        };
    }

    /**
     * Statuses visible on the public site (drafts never are).
     *
     * @return list<self>
     */
    public static function publicCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status !== self::Draft));
    }

    /**
     * Statuses shown in the default ("upcoming and live") listing.
     *
     * @return list<self>
     */
    public static function activeCases(): array
    {
        return [self::Open, self::Full, self::Scheduled, self::Starting, self::InProgress];
    }
}
