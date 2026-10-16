<?php

namespace Tests\Unit\Results;

use App\Enums\TieBreakPolicy;
use App\Services\Results\ScoringPolicy;
use PHPUnit\Framework\TestCase;

class ScoringPolicyTest extends TestCase
{
    /** Three questions, each open 0–20 s after a start at t=0 (deadline incl. grace 20.5 s), offset per question. */
    protected function questions(array $voided = []): array
    {
        $questions = [];
        foreach ([1 => 0, 2 => 30_000, 3 => 60_000] as $id => $opens) {
            $questions[$id] = ['opens_at_ms' => $opens, 'deadline_ms' => $opens + 20_500, 'voided' => in_array($id, $voided, true)];
        }

        return $questions;
    }

    protected function a(int $participant, int $question, int $afterOpenMs, bool $correct): array
    {
        $opens = [1 => 0, 2 => 30_000, 3 => 60_000][$question];

        return ['participant_id' => $participant, 'question_id' => $question, 'received_ms' => $opens + $afterOpenMs, 'is_correct' => $correct];
    }

    protected function players(int ...$ids): array
    {
        return array_map(fn ($id) => ['id' => $id, 'excluded' => null], $ids);
    }

    protected function score(array $players, array $answers, TieBreakPolicy $policy = TieBreakPolicy::FastestCorrect, array $voided = []): array
    {
        return collect((new ScoringPolicy)->score($players, $this->questions($voided), $answers, 0, $policy))->keyBy('participant_id')->all();
    }

    public function test_perfect_score(): void
    {
        $r = $this->score($this->players(1), [$this->a(1, 1, 1000, true), $this->a(1, 2, 2000, true), $this->a(1, 3, 3000, true)]);

        $this->assertSame(3, $r[1]['score']);
        $this->assertSame(3, $r[1]['correct_count']);
        $this->assertSame(3, $r[1]['answered_count']);
        $this->assertSame(6000, $r[1]['correct_time_ms'], 'Sum of per-question answer times, not competition duration.');
        $this->assertSame(63_000, $r[1]['last_correct_elapsed_ms']);
        $this->assertSame(1, $r[1]['rank']);
    }

    public function test_incorrect_and_unanswered_questions_score_zero_and_add_no_time(): void
    {
        $r = $this->score($this->players(1, 2), [$this->a(1, 1, 1000, true), $this->a(1, 2, 500, false)]);

        $this->assertSame(1, $r[1]['score']);
        $this->assertSame(2, $r[1]['answered_count']);
        $this->assertSame(1000, $r[1]['correct_time_ms'], 'Wrong answers add no time.');
        $this->assertSame(0, $r[2]['score']);
        $this->assertSame(0, $r[2]['answered_count']);
        $this->assertNull($r[2]['last_correct_elapsed_ms']);
        $this->assertSame([1, 2], [$r[1]['rank'], $r[2]['rank']]);
    }

    public function test_late_and_early_answers_earn_nothing(): void
    {
        $r = $this->score($this->players(1), [
            $this->a(1, 1, 20_500, true),  // exactly at deadline incl. grace: counts
            $this->a(1, 2, 20_501, true),  // 1 ms late
            $this->a(1, 3, -1, true),      // before opening
        ]);

        $this->assertSame(1, $r[1]['score']);
        $this->assertSame(1, $r[1]['answered_count']);
    }

    public function test_voided_questions_are_ignored_for_everyone(): void
    {
        $r = $this->score($this->players(1), [$this->a(1, 1, 1000, true), $this->a(1, 2, 1000, true)], voided: [2]);

        $this->assertSame(1, $r[1]['score']);
        $this->assertSame(1000, $r[1]['correct_time_ms']);
    }

    public function test_more_correct_answers_always_beat_faster_time(): void
    {
        $r = $this->score($this->players(1, 2), [
            $this->a(1, 1, 19_000, true), $this->a(1, 2, 19_000, true),
            $this->a(2, 1, 100, true),
        ]);

        $this->assertSame([1, 2], [$r[1]['rank'], $r[2]['rank']]);
    }

    public function test_equal_scores_are_ranked_by_lower_correct_answer_time(): void
    {
        $r = $this->score($this->players(1, 2, 3), [
            $this->a(1, 1, 5000, true), $this->a(1, 2, 5000, true),
            $this->a(2, 1, 3000, true), $this->a(2, 2, 4000, true),
            $this->a(3, 1, 9000, true), $this->a(3, 2, 9000, true),
        ]);

        $this->assertSame([2, 1, 3], [$r[1]['rank'], $r[2]['rank'], $r[3]['rank']]);
    }

    public function test_third_tie_break_is_time_to_the_final_correct_answer(): void
    {
        // Same count and same total correct time (6000), but player 2 finished earlier.
        $r = $this->score($this->players(1, 2), [
            $this->a(1, 1, 1000, true), $this->a(1, 3, 5000, true),
            $this->a(2, 1, 5000, true), $this->a(2, 2, 1000, true),
        ]);

        $this->assertSame(65_000, $r[1]['last_correct_elapsed_ms']);
        $this->assertSame(31_000, $r[2]['last_correct_elapsed_ms']);
        $this->assertSame([2, 1], [$r[1]['rank'], $r[2]['rank']]);
    }

    public function test_exact_ties_share_a_rank_and_the_next_rank_is_skipped(): void
    {
        $r = $this->score($this->players(1, 2, 3, 4), [
            $this->a(1, 1, 1000, true),
            $this->a(2, 1, 2000, true),
            $this->a(3, 1, 2000, true),
            $this->a(4, 1, 3000, true),
        ]);

        $this->assertSame([1, 2, 2, 4], [$r[1]['rank'], $r[2]['rank'], $r[3]['rank'], $r[4]['rank']]);
    }

    public function test_shared_policy_ignores_time(): void
    {
        $r = $this->score($this->players(1, 2, 3), [
            $this->a(1, 1, 1000, true),
            $this->a(2, 1, 19_000, true),
        ], TieBreakPolicy::Shared);

        $this->assertSame([1, 1, 3], [$r[1]['rank'], $r[2]['rank'], $r[3]['rank']]);
    }

    public function test_players_with_no_correct_answers_tie_with_each_other(): void
    {
        $r = $this->score($this->players(1, 2, 3), [$this->a(1, 1, 1000, true), $this->a(2, 1, 100, false)]);

        $this->assertSame([1, 2, 2], [$r[1]['rank'], $r[2]['rank'], $r[3]['rank']]);
    }

    public function test_excluded_participants_are_scored_but_not_ranked(): void
    {
        $players = [['id' => 1, 'excluded' => 'Disqualified: collusion'], ['id' => 2, 'excluded' => null]];
        $r = $this->score($players, [$this->a(1, 1, 100, true), $this->a(2, 1, 9000, true)]);

        $this->assertNull($r[1]['rank']);
        $this->assertSame(1, $r[1]['score']);
        $this->assertSame(1, $r[2]['rank']);
    }

    public function test_zero_participants(): void
    {
        $this->assertSame([], (new ScoringPolicy)->score([], $this->questions(), [], 0, TieBreakPolicy::FastestCorrect));
    }

    public function test_answers_from_unknown_participants_are_ignored(): void
    {
        $r = $this->score($this->players(1), [$this->a(99, 1, 1000, true)]);

        $this->assertCount(1, $r);
        $this->assertSame(0, $r[1]['score']);
    }

    public function test_result_is_independent_of_input_order(): void
    {
        $answers = [$this->a(1, 1, 3000, true), $this->a(2, 1, 1000, true), $this->a(3, 1, 2000, true), $this->a(1, 2, 1000, false)];

        $forward = $this->score($this->players(1, 2, 3), $answers);
        $reversed = $this->score(array_reverse($this->players(1, 2, 3)), array_reverse($answers));

        ksort($forward);
        ksort($reversed);
        $this->assertSame($forward, $reversed);
    }
}
