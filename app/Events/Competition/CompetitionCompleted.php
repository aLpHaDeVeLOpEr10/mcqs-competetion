<?php

namespace App\Events\Competition;

use App\Models\CompetitionSession;

/**
 * The live part is over: either all questions closed (status "scoring",
 * results follow) or the competition was aborted.
 */
class CompetitionCompleted extends CompetitionEvent
{
    public function __construct(public readonly CompetitionSession $session)
    {
        parent::__construct($session->id);
    }

    public function broadcastAs(): string
    {
        return 'competition.completed';
    }

    protected function payload(): array
    {
        return [
            'status' => $this->session->status->value,
            'ended_at' => self::iso($this->session->ended_at ?? $this->session->aborted_at),
        ];
    }
}
