<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A user's seat in a room. One row per (room, user); a user who withdrew or
 * whose reservation expired reuses the same row when joining again.
 * Reserved = seat held while an entry payment is pending (time-limited).
 */
enum ParticipantStatus: string
{
    use HasValues;

    case Reserved = 'reserved';
    case Joined = 'joined';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Playing = 'playing';
    case Finished = 'finished';
    case Disqualified = 'disqualified';
    case Refunded = 'refunded';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Reserved => [self::Joined, self::Withdrawn, self::Expired],
            self::Joined => [self::Withdrawn, self::Playing, self::Disqualified, self::Refunded],
            self::Withdrawn, self::Expired => [self::Reserved, self::Joined],
            self::Playing => [self::Finished, self::Disqualified, self::Refunded],
            self::Finished => [self::Disqualified],
            self::Disqualified, self::Refunded => [],
        };
    }

    /**
     * The player's own participation, in their words.
     */
    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Seat reserved',
            self::Joined => 'Registered',
            self::Withdrawn => 'You withdrew',
            self::Expired => 'Reservation expired',
            self::Playing => 'Playing',
            self::Finished => 'Played',
            self::Disqualified => 'Disqualified',
            self::Refunded => 'Refunded',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Whether the seat counts toward room capacity.
     */
    public function occupiesSeat(): bool
    {
        return in_array($this, [self::Reserved, self::Joined, self::Playing, self::Finished, self::Disqualified], true);
    }
}
