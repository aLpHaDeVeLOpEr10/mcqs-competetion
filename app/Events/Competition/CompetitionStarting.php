<?php

namespace App\Events\Competition;

use App\Models\CompetitionSession;

class CompetitionStarting extends CompetitionEvent
{
    public function __construct(public readonly CompetitionSession $session)
    {
        parent::__construct($session->id);
    }

    public function broadcastAs(): string
    {
        return 'competition.starting';
    }

    protected function payload(): array
    {
        return [
            'starts_at' => self::iso($this->session->starts_at),
            'question_count' => (int) ($this->session->config['question_count'] ?? 0),
        ];
    }
}
