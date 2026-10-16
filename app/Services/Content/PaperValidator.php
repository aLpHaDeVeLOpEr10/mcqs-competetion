<?php

namespace App\Services\Content;

use App\Models\QuizPaper;
use App\Models\QuizPaperQuestion;

/**
 * Rules a paper must satisfy to be published or snapshotted.
 */
class PaperValidator
{
    /**
     * @return list<string> empty when the paper is valid
     */
    public function problems(QuizPaper $paper): array
    {
        $items = $paper->items()->with(['question' => fn ($q) => $q->withTrashed()->with('options')])->get();
        $count = $items->count();
        $min = (int) config('competition.papers.min_questions');
        $max = (int) config('competition.papers.max_questions');
        $minTime = (int) config('competition.questions.min_time_limit');
        $maxTime = (int) config('competition.questions.max_time_limit');
        $problems = [];

        if ($count < $min) {
            $problems[] = "The paper has {$count} question(s); at least {$min} are required.";
        }

        if ($count > $max) {
            $problems[] = "The paper has {$count} questions; at most {$max} are allowed.";
        }

        if ($paper->target_question_count !== null && $count !== $paper->target_question_count) {
            $problems[] = "The paper is set to {$paper->target_question_count} questions but contains {$count}.";
        }

        $positions = $items->pluck('position')->all();
        if ($positions !== range(1, max(1, $count)) && $count > 0) {
            $problems[] = 'Question positions are not a continuous 1…n sequence.';
        }

        foreach ($items as $item) {
            /** @var QuizPaperQuestion $item */
            foreach ($item->question->integrityProblems() as $problem) {
                $problems[] = "Question #{$item->position} (ID {$item->question_id}) {$problem}.";
            }

            $limit = $item->time_limit_seconds ?? $item->question->default_time_limit_seconds ?? $paper->default_time_limit_seconds;
            if ($limit < $minTime || $limit > $maxTime) {
                $problems[] = "Question #{$item->position} has a time limit of {$limit} s (allowed {$minTime}–{$maxTime} s).";
            }
        }

        return $problems;
    }
}
