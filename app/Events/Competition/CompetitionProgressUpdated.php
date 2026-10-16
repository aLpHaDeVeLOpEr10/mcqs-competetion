<?php

namespace App\Events\Competition;

/**
 * Aggregate progress only: no individual answers or scores.
 */
class CompetitionProgressUpdated extends CompetitionEvent
{
    public function __construct(int $sessionId, public readonly int $position, public readonly int $total, public readonly int $answered, public readonly int $players)
    {
        parent::__construct($sessionId);
    }

    public function broadcastAs(): string
    {
        return 'competition.progress';
    }

    protected function payload(): array
    {
        return [
            'position' => $this->position,
            'total' => $this->total,
            'answered' => $this->answered,
            'players' => $this->players,
        ];
    }
}
