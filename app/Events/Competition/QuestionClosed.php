<?php

namespace App\Events\Competition;

use App\Models\SessionQuestion;

/**
 * The answer window has closed. No correct answer is revealed during the
 * live competition.
 */
class QuestionClosed extends CompetitionEvent
{
    public function __construct(public readonly SessionQuestion $question, public readonly ?SessionQuestion $next)
    {
        parent::__construct($question->competition_session_id);
    }

    public function broadcastAs(): string
    {
        return 'question.closed';
    }

    protected function payload(): array
    {
        return [
            'question_id' => $this->question->id,
            'position' => $this->question->position,
            'closed_at' => self::iso($this->question->closed_at),
            'next_opens_at' => self::iso($this->next?->opens_at),
        ];
    }
}
