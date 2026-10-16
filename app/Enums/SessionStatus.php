<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Engine-side lifecycle of a competition session (the live run of a room).
 */
enum SessionStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Countdown = 'countdown';
    case InProgress = 'in_progress';
    case Scoring = 'scoring';
    case Completed = 'completed';
    case Aborted = 'aborted';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Countdown, self::Aborted],
            self::Countdown => [self::InProgress, self::Aborted],
            self::InProgress => [self::Scoring, self::Aborted],
            self::Scoring => [self::Completed, self::Aborted],
            self::Completed, self::Aborted => [],
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
}
