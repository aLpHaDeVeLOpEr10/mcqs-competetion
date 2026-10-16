<?php

namespace App\Services\Results;

use App\Enums\TieBreakPolicy;

/**
 * Scoring policy "correct_count_v1". Pure: no database or clock access,
 * so the same inputs always give the same ranking.
 *
 * Points: every correct answer = 1 point. Incorrect, unanswered, late
 *   (received after the question's deadline incl. grace), early, and
 *   answers to voided questions = 0 and are not counted as answered.
 * Answer time (per counted answer, ms): received_at − question opens_at,
 *   where opens_at is the question's actual opening (delivery) time.
 * Correct-answer time: sum of answer times over CORRECT counted answers
 *   only. It is not the competition duration.
 * Last-correct time: ms from the competition start to the receipt of the
 *   participant's last counted correct answer (null if none).
 *
 * Ranking (descending priority):
 *   1. more correct answers
 *   2. lower correct-answer time
 *   3. (fastest_correct policy only) lower last-correct time
 *   4. still equal → the SAME rank, shared ("1, 2, 2, 4"). No winner is
 *      ever chosen arbitrarily; prize places of a shared rank are pooled
 *      and split (see PrizeCalculator).
 * The tie_break_policy "shared" stops after step 1.
 * Excluded participants (e.g. disqualified) are scored but not ranked.
 */
class ScoringPolicy
{
    public const VERSION = 'correct_count_v1';

    /**
     * @param  list<array{id: int, excluded: ?string}>  $participants
     * @param  array<int, array{opens_at_ms: ?int, deadline_ms: ?int, voided: bool}>  $questions  keyed by question id
     * @param  list<array{participant_id: int, question_id: int, received_ms: int, is_correct: bool}>  $answers
     * @return list<array{participant_id: int, answered_count: int, correct_count: int, score: int, correct_time_ms: int, last_correct_elapsed_ms: ?int, rank: ?int, excluded: ?string}>
     */
    public function score(array $participants, array $questions, array $answers, int $startedMs, TieBreakPolicy $tieBreak): array
    {
        $rows = [];
        foreach ($participants as $p) {
            $rows[$p['id']] = [
                'participant_id' => $p['id'],
                'answered_count' => 0,
                'correct_count' => 0,
                'score' => 0,
                'correct_time_ms' => 0,
                'last_correct_elapsed_ms' => null,
                'rank' => null,
                'excluded' => $p['excluded'],
            ];
        }

        foreach ($answers as $answer) {
            $question = $questions[$answer['question_id']] ?? null;

            if (! isset($rows[$answer['participant_id']]) || $question === null || $question['voided']
                || ! $this->withinWindow($answer['received_ms'], $question)) {
                continue;
            }

            $row = &$rows[$answer['participant_id']];
            $row['answered_count']++;

            if ($answer['is_correct']) {
                $row['correct_count']++;
                $row['score']++;
                $row['correct_time_ms'] += $answer['received_ms'] - $question['opens_at_ms'];
                $elapsed = max(0, $answer['received_ms'] - $startedMs);
                $row['last_correct_elapsed_ms'] = max($row['last_correct_elapsed_ms'] ?? 0, $elapsed);
            }
            unset($row);
        }

        $ranked = array_values(array_filter($rows, fn ($r) => $r['excluded'] === null));
        $excluded = array_values(array_filter($rows, fn ($r) => $r['excluded'] !== null));

        usort($ranked, fn ($a, $b) => $this->compare($a, $b, $tieBreak) ?: $a['participant_id'] <=> $b['participant_id']);

        // Standard competition ranking: equal keys share a rank, the next rank skips.
        foreach ($ranked as $i => &$row) {
            $row['rank'] = ($i > 0 && $this->compare($ranked[$i - 1], $row, $tieBreak) === 0) ? $ranked[$i - 1]['rank'] : $i + 1;
        }
        unset($row);

        usort($excluded, fn ($a, $b) => $a['participant_id'] <=> $b['participant_id']);

        return [...$ranked, ...$excluded];
    }

    /**
     * Ordering by ranking keys only (participant id is used solely to make the
     * output order stable; it never decides a rank).
     */
    public function compare(array $a, array $b, TieBreakPolicy $tieBreak): int
    {
        $result = $b['correct_count'] <=> $a['correct_count'];

        if ($result !== 0 || $tieBreak === TieBreakPolicy::Shared) {
            return $result;
        }

        $result = $a['correct_time_ms'] <=> $b['correct_time_ms'];

        if ($result !== 0) {
            return $result;
        }

        return ($a['last_correct_elapsed_ms'] ?? PHP_INT_MAX) <=> ($b['last_correct_elapsed_ms'] ?? PHP_INT_MAX);
    }

    /**
     * @param  array{opens_at_ms: ?int, deadline_ms: ?int}  $question
     */
    protected function withinWindow(int $receivedMs, array $question): bool
    {
        return $question['opens_at_ms'] !== null
            && $question['deadline_ms'] !== null
            && $receivedMs >= $question['opens_at_ms']
            && $receivedMs <= $question['deadline_ms'];
    }
}
