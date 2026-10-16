<?php

namespace App\Events\Competition;

use App\Models\SessionQuestion;
use App\Services\Engine\QuestionPresenter;

/**
 * Delivers one question to every participant at the same moment. Contains
 * the text and options only; correctness is never included.
 */
class QuestionOpened extends CompetitionEvent
{
    public function __construct(public readonly SessionQuestion $question, public readonly int $total)
    {
        parent::__construct($question->competition_session_id);
    }

    public function broadcastAs(): string
    {
        return 'question.opened';
    }

    protected function payload(): array
    {
        return ['question' => QuestionPresenter::forPlayer($this->question, $this->total)];
    }
}
