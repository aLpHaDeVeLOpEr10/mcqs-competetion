<?php

namespace App\Services\Results;

use App\Models\AnswerSubmission;
use App\Models\CompetitionSession;
use App\Models\RoomParticipant;
use Illuminate\Support\Collection;

/**
 * One player's own answers for a competition, question by question. Only
 * the given seat's submissions are read, so no other participant's answers
 * can leak. Correctness and the correct option are included only when
 * $reveal is true (results published); before that a player sees what they
 * submitted and how long it took, nothing more.
 */
class AnswerReview
{
    /**
     * @return Collection<int, array{question: mixed, answer: ?AnswerSubmission, chosen: mixed, correct: mixed, outcome: string}>
     */
    public function forSeat(RoomParticipant $seat, CompetitionSession $session, bool $reveal): Collection
    {
        $answers = AnswerSubmission::query()->where('room_participant_id', $seat->id)->get()->keyBy('session_question_id');

        return $session->questions()->with('options')->orderBy('position')->get()
            // Questions never shown (e.g. an aborted competition) are not part of the review.
            ->filter(fn ($question) => $question->delivered_at !== null)
            ->values()
            ->map(function ($question) use ($answers, $reveal) {
                $answer = $answers->get($question->id);
                $correct = $question->options->firstWhere('is_correct', true);

                return [
                    'question' => $question,
                    'answer' => $answer,
                    'chosen' => $answer ? $question->options->firstWhere('id', $answer->session_question_option_id) : null,
                    'correct' => $reveal ? $correct : null,
                    'outcome' => match (true) {
                        $question->voided_at !== null => 'voided',
                        $answer === null => 'unanswered',
                        ! $reveal => 'submitted',
                        $answer->session_question_option_id === $correct?->id => 'correct',
                        default => 'incorrect',
                    },
                ];
            });
    }
}
