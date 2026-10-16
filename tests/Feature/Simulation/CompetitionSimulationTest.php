<?php

namespace Tests\Feature\Simulation;

use App\Enums\LedgerTransactionType;
use App\Enums\ParticipantStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuizPaperStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Enums\TieBreakPolicy;
use App\Exceptions\AdministrativeActionException;
use App\Jobs\DistributePrizes;
use App\Models\AnswerSubmission;
use App\Models\LedgerTransaction;
use App\Models\ParticipantResult;
use App\Models\QuizPaper;
use App\Models\ResultVersion;
use App\Models\RoomParticipant;
use App\Models\SessionQuestion;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Engine\CompetitionRecovery;
use App\Services\Prizes\PrizeDistributionService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\AssertsFinancialInvariants;
use Tests\Support\PlaysCompetitions;
use Tests\Support\UsesFakeGateway;
use Tests\TestCase;

/**
 * End-to-end competition simulations through the real HTTP endpoints, engine,
 * scoring, prizes and ledger. Every scenario ends with the whole-database
 * financial invariants. (Scenario F, the last-seat race, needs separate
 * processes: see LastSeatRaceTest.)
 */
class CompetitionSimulationTest extends TestCase
{
    use AssertsFinancialInvariants, PlaysCompetitions, RefreshDatabase, UsesFakeGateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['competition.prizes.payouts_enabled' => true, 'competition.paid_entries.enabled' => true]);
    }

    protected function paper(int $questions): int
    {
        $paper = QuizPaper::factory()->withQuestions($questions)->create();
        $paper->forceFill(['status' => QuizPaperStatus::Published])->save();

        return $paper->id;
    }

    protected function grace(): int
    {
        return (int) config('competition.engine.answer_grace_ms');
    }

    protected function balance(int $player): int
    {
        return app(WalletService::class)->getAvailableBalance($this->players[$player]);
    }

    protected function state(User $user): array
    {
        return $this->actingAs($user)->getJson(route('play.state', $this->room))->assertOk()->json();
    }

    /**
     * Opens question $position (when due) and returns it fresh.
     */
    protected function openQuestion(int $position): SessionQuestion
    {
        $question = $this->question($position);
        if ($question->delivered_at === null) {
            $this->travelTo($question->opens_at);
            $this->advance();
        }

        return $this->question($position);
    }

    protected function closeQuestion(SessionQuestion $question): void
    {
        $this->travelTo($question->closes_at->copy()->addMilliseconds($this->grace() + 1));
        $this->advance();
    }

    /**
     * Plays every question through HTTP. $plan[position][player] = [afterOpenMs, correct?].
     * Returns, per position, what each player's screen received (question id, deadline, options).
     *
     * @return array<int, array<int, string>>
     */
    protected function playViaHttp(array $plan): array
    {
        $seen = [];
        $total = $this->session->questions()->count();

        for ($position = 1; $position <= $total; $position++) {
            if ($this->question($position)->closed_at !== null) {
                continue;   // already played by the scenario itself
            }
            $question = $this->openQuestion($position);

            foreach ($this->players as $index => $player) {
                $q = $this->state($player)['question'];
                $seen[$position][$index] = json_encode([$q['id'], $q['position'], $q['opens_at'], $q['closes_at'], array_column($q['options'], 'id')]);
            }

            $answers = $plan[$position] ?? [];
            uasort($answers, fn ($a, $b) => $a[0] <=> $b[0]);
            foreach ($answers as $index => [$afterMs, $correct]) {
                $this->travelTo($question->opens_at->copy()->addMilliseconds($afterMs));
                $this->answer($this->players[$index], $question, $this->optionId($question, $correct))->assertOk();
            }

            $this->closeQuestion($question);
        }

        $this->session->refresh();

        return $seen;
    }

    /**
     * @return array<int, array{rank: ?int, correct: int, time: int}> keyed by player index
     */
    protected function results(): array
    {
        $out = [];
        foreach ($this->players as $index => $player) {
            $result = ParticipantResult::query()->whereHas('participant', fn ($q) => $q->where('user_id', $player->id))->sole();
            $out[$index] = ['rank' => $result->rank, 'correct' => $result->correct_count, 'time' => $result->correct_response_time_ms];
        }

        return $out;
    }

    public function test_scenario_a_ten_players_ten_questions_everyone_finishes(): void
    {
        $this->competitionWithPrizes(10, [[1, 1, 10_000], [2, 2, 5_000], [3, 3, 2_500]], ['quiz_paper_id' => $this->paper(10)]);
        $this->assertSame(10, $this->session->questions()->count());

        // Player i answers questions 1..(10 - i) correctly and the rest wrongly, at 1 s + i × 100 ms.
        $plan = [];
        foreach (range(1, 10) as $q) {
            foreach (range(0, 9) as $i) {
                $plan[$q][$i] = [1000 + $i * 100, $q <= 10 - $i];
            }
        }
        $seen = $this->playViaHttp($plan);

        // Same authoritative sequence for everyone: same question, options and deadline at every step.
        foreach ($seen as $position => $screens) {
            $this->assertCount(1, array_unique($screens), "Question {$position} was identical for all ten players.");
        }

        // Deadlines: every window is exactly the time limit, and each opens a fixed gap after the previous closed.
        $questions = $this->session->questions()->orderBy('position')->get();
        foreach ($questions as $q) {
            $this->assertSame($q->time_limit_seconds * 1000, (int) $q->opens_at->diffInMilliseconds($q->closes_at));
            if ($q->position > 1) {
                $previous = $questions[$q->position - 2];
                $this->assertSame($this->room->question_gap_seconds * 1000, (int) $previous->closed_at->diffInMilliseconds($q->opens_at));
            }
        }

        // Submissions persisted: one per player per question, with server-side times.
        $this->assertSame(100, AnswerSubmission::count());
        $this->assertSame(SessionStatus::Completed, $this->session->status);

        // Scores and ranks.
        foreach ($this->results() as $i => $result) {
            $this->assertSame(10 - $i, $result['correct'], "Player {$i} correct count.");
            $this->assertSame((10 - $i) * (1000 + $i * 100), $result['time'], "Player {$i} time on correct answers.");
            $this->assertSame($i + 1, $result['rank'], "Player {$i} rank.");
        }

        // Prizes.
        $this->assertSame([10_000, 5_000, 2_500], [$this->balance(0), $this->balance(1), $this->balance(2)]);
        foreach (range(3, 9) as $i) {
            $this->assertSame(0, $this->balance($i));
        }
        $this->assertSame(1, ResultVersion::query()->count());
        $this->assertFinancialInvariants();
    }

    public function test_scenario_b_equal_scores_are_ranked_by_time_on_correct_answers(): void
    {
        $this->competitionWithPrizes(4, [[1, 1, 10_000], [2, 2, 5_000], [3, 3, 2_500]]);
        $plan = [];
        foreach (range(1, 5) as $q) {
            // Players 0, 1 and 2 all answer every question correctly; only their speed differs.
            $plan[$q] = [0 => [1500, true], 1 => [1000, true], 2 => [2000, true], 3 => [500, false]];
        }
        $this->playViaHttp($plan);

        $results = $this->results();
        $this->assertSame([5, 5, 5, 0], array_column($results, 'correct'));
        $this->assertSame([2, 1, 3, 4], array_column($results, 'rank'), 'Fastest total time on correct answers wins.');
        $this->assertSame([5_000, 10_000, 2_500, 0], [$this->balance(0), $this->balance(1), $this->balance(2), $this->balance(3)]);
        $this->assertFinancialInvariants();
    }

    public function test_scenario_c_exact_ties_follow_the_configured_policy(): void
    {
        // Fastest-correct policy, identical scores AND identical times: the rank is shared,
        // and the tied places' prizes are pooled and split.
        $this->competitionWithPrizes(3, [[1, 1, 10_000], [2, 2, 5_000]], ['tie_break_policy' => TieBreakPolicy::FastestCorrect]);
        $plan = [];
        foreach (range(1, 5) as $q) {
            $plan[$q] = [0 => [1200, true], 1 => [1200, true], 2 => [900, $q <= 3]];
        }
        $this->playViaHttp($plan);

        $this->assertSame([1, 1, 3], array_column($this->results(), 'rank'));
        $this->assertSame([7_500, 7_500, 0], [$this->balance(0), $this->balance(1), $this->balance(2)]);
        $this->assertFinancialInvariants();
    }

    public function test_scenario_c2_the_shared_policy_ignores_time_between_equal_scores(): void
    {
        $this->competitionWithPrizes(3, [[1, 1, 9_001], [2, 2, 3_000]], ['tie_break_policy' => TieBreakPolicy::Shared]);
        $plan = [];
        foreach (range(1, 5) as $q) {
            $plan[$q] = [0 => [800, true], 1 => [4000, true], 2 => [500, $q <= 2]];
        }
        $this->playViaHttp($plan);

        $this->assertSame([1, 1, 3], array_column($this->results(), 'rank'), 'Equal scores share the rank whatever the time.');
        // (9,001 + 3,000) / 2 = 6,000 each, floor; 1 paisa stays unallocated (never invented).
        $this->assertSame([6_000, 6_000, 0], [$this->balance(0), $this->balance(1), $this->balance(2)]);
        $this->assertSame(1, (int) $this->session->prizeDistribution->fresh()->unallocated_minor);
        $this->assertFinancialInvariants();
    }

    public function test_scenario_d_disconnect_in_question_4_and_reconnect_in_question_5(): void
    {
        $this->competitionWithPrizes(3, [[1, 1, 10_000]]);
        $player = $this->players[0];

        foreach (range(1, 3) as $position) {
            $question = $this->openQuestion($position);
            $this->travelTo($question->opens_at->copy()->addSecond());
            foreach ($this->players as $p) {
                $this->answer($p, $question, $this->optionId($question))->assertOk();
            }
            $this->closeQuestion($question);
        }

        // Question 4: the player sees it, then their connection drops (no more requests).
        $q4 = $this->openQuestion(4);
        $this->travelTo($q4->opens_at->copy()->addSecond());
        $this->assertSame($q4->id, $this->state($player)['question']['id']);
        $this->answer($this->players[1], $q4, $this->optionId($q4))->assertOk();
        $this->closeQuestion($q4);

        // Question 5 is running when they come back.
        $q5 = $this->openQuestion(5);
        $deadline = $q5->closes_at->format('Y-m-d\TH:i:s.vP');
        $this->travelTo($q5->opens_at->copy()->addSeconds(3));

        $state = $this->state($player);
        $this->assertSame($q5->id, $state['question']['id'], 'Reconnect lands on the current question.');
        $this->assertSame($deadline, $state['question']['closes_at'], 'The deadline is the original one: no timer reset.');
        $this->assertNull($state['my_answer']);
        $this->assertSame(3, $state['answered_count'], 'Earlier answers are restored.');

        $this->travel(2)->seconds();
        $this->assertSame($deadline, $this->state($player)['question']['closes_at'], 'Reconnecting again changes nothing.');

        // The missed question cannot be answered late; the current one can.
        $this->answer($player, $q4, $this->optionId($q4))->assertStatus(409)->assertJson(['reason' => 'out_of_sequence']);
        $this->answer($player, $q5, $this->optionId($q5))->assertOk();
        $this->closeQuestion($q5);

        $this->session->refresh();
        $this->assertSame(SessionStatus::Completed, $this->session->status);
        $this->assertSame(4, $this->results()[0]['correct'], 'Question 4 counts as unanswered.');
        $this->assertFinancialInvariants();
    }

    public function test_scenario_e_answers_after_the_deadline_do_not_count(): void
    {
        $this->competitionWithPrizes(2, [[1, 1, 10_000]]);
        $q1 = $this->openQuestion(1);

        // Inside the grace window: accepted (network latency allowance).
        $this->travelTo($q1->closes_at->copy()->addMilliseconds($this->grace()));
        $this->answer($this->players[0], $q1, $this->optionId($q1))->assertOk();

        // One millisecond later: refused, even though the engine has not closed the question yet.
        $this->travelTo($q1->closes_at->copy()->addMilliseconds($this->grace() + 1));
        $this->assertNull($q1->fresh()->closed_at);
        $this->answer($this->players[1], $q1, $this->optionId($q1))->assertStatus(409)->assertJson(['reason' => 'closed']);
        $this->assertSame(1, AnswerSubmission::count());

        $this->closeQuestion($q1);
        $this->playViaHttp([]);   // the rest unanswered

        $results = $this->results();
        $this->assertSame([1, 0], array_column($results, 'correct'));
        $this->assertSame([1, 2], array_column($results, 'rank'));
        $this->assertSame([10_000, 0], [$this->balance(0), $this->balance(1)]);
        $this->assertFinancialInvariants();
    }

    public function test_scenario_g_cancellation_refunds_entry_fees_exactly_once(): void
    {
        $wallets = app(WalletService::class);
        $fund = function (User $user) use ($wallets) {
            $wallets->credit($wallets->walletFor($user), 20_000, LedgerTransactionType::Deposit, 'seed:'.$user->id);
        };

        // Before the start: staff cancel.
        $room = $this->openRoom(['entry_fee_minor' => 5_000, 'min_participants' => 5, 'max_participants' => 10]);
        $players = User::factory()->count(4)->create()->each($fund);
        $players->each(fn ($p) => app(RoomEntryService::class)->join($p, $room));
        $players->each(fn ($p) => $this->assertSame(15_000, $wallets->getAvailableBalance($p)));

        app(RoomLifecycle::class)->cancel($room, null, 'Venue problem');
        $refunds = fn () => LedgerTransaction::query()->where('type', LedgerTransactionType::EntryRefund)->count();
        $this->assertSame(4, $refunds());

        // Every way of trying again: cancel again, recovery, releasing a seat again.
        try {
            app(RoomLifecycle::class)->cancel($room->fresh(), null, 'Again');
            $this->fail('A cancelled room cannot be cancelled again.');
        } catch (AdministrativeActionException) {
        }
        app(CompetitionRecovery::class)->run();
        $this->artisan('competitions:recover')->assertSuccessful();
        foreach (RoomParticipant::query()->where('room_id', $room->id)->get() as $seat) {
            app(WalletService::class)->refundEntryFee($seat, 'retry');
        }

        $this->assertSame(4, $refunds());
        $players->each(fn ($p) => $this->assertSame(20_000, $wallets->getAvailableBalance($p)));
        $this->assertSame(RoomStatus::Refunded, $room->fresh()->status);

        // During the competition: aborted mid-way, every playing seat refunded once.
        $this->competitionWithPrizes(3, [[1, 1, 10_000]], ['entry_fee_minor' => 2_000]);
        $q1 = $this->openQuestion(1);
        $this->travelTo($q1->opens_at->copy()->addSecond());
        $this->answer($this->players[0], $q1, $this->optionId($q1))->assertOk();
        app(RoomLifecycle::class)->cancel($this->room, null, 'Technical fault');

        $this->assertSame(7, $refunds());
        $this->assertSame(SessionStatus::Aborted, $this->session->fresh()->status);
        $this->assertSame(3, RoomParticipant::query()->where('room_id', $this->room->id)->where('status', ParticipantStatus::Refunded)->count());
        foreach (range(0, 2) as $i) {
            $this->assertSame(2_000, $this->balance($i), 'Funded with exactly the fee, which came back.');
        }
        $this->assertSame(0, LedgerTransaction::query()->where('type', LedgerTransactionType::PrizePayout)->count(), 'A cancelled competition pays no prizes.');
        $this->assertFinancialInvariants();
    }

    public function test_scenario_h_the_prize_job_running_twice_pays_once(): void
    {
        $this->competitionWithPrizes(3, [[1, 1, 10_000], [2, 2, 5_000]]);
        config(['queue.default' => 'database']);
        Queue::fake();

        $plan = [];
        foreach (range(1, 5) as $q) {
            $plan[$q] = [0 => [1000, true], 1 => [1000, $q <= 4], 2 => [1000, false]];
        }
        $this->playViaHttp($plan);
        Queue::assertPushed(DistributePrizes::class, 1);

        $service = app(PrizeDistributionService::class);
        (new DistributePrizes($this->session->id))->handle($service);
        (new DistributePrizes($this->session->id))->handle($service);   // duplicate / retried job
        $this->artisan('prizes:distribute')->assertSuccessful();       // and the scheduler

        $this->assertSame(2, LedgerTransaction::query()->where('type', LedgerTransactionType::PrizePayout)->count());
        $this->assertSame([10_000, 5_000, 0], [$this->balance(0), $this->balance(1), $this->balance(2)]);
        $this->assertFinancialInvariants();
    }

    public function test_scenario_i_a_repeated_payment_callback_credits_once(): void
    {
        $this->enableFakeGateway();
        $player = User::factory()->create();
        $payment = $this->startDeposit($player, 50_000);
        $this->fake()->setProviderStatus($payment->reference, 'succeeded');
        $notification = $this->fake()->notification($this->providerState($payment));

        foreach (range(1, 5) as $i) {
            $this->deliver($notification)->assertOk();
        }
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(1, LedgerTransaction::query()->where('type', LedgerTransactionType::Deposit)->count());
        $this->assertSame(50_000, app(WalletService::class)->getAvailableBalance($player));
        $this->assertFinancialInvariants();
    }

    public function test_scenario_j_interruption_around_a_question_transition_recovers(): void
    {
        $this->competitionWithPrizes(3, [[1, 1, 10_000]]);
        $q1 = $this->openQuestion(1);
        $this->travelTo($q1->opens_at->copy()->addSecond());
        $this->answer($this->players[0], $q1, $this->optionId($q1))->assertOk();

        // The process dies in the middle of closing question 1 (inside the transaction).
        $crash = true;
        Event::listen('eloquent.saving: '.SessionQuestion::class, function () use (&$crash) {
            if ($crash) {
                $crash = false;
                throw new RuntimeException('Process killed');
            }
        });
        $this->travelTo($q1->closes_at->copy()->addMilliseconds($this->grace() + 1));
        try {
            $this->advance();
            $this->fail('The simulated crash should surface.');
        } catch (RuntimeException) {
        }
        $this->assertNull($q1->fresh()->closed_at, 'Nothing half-applied.');
        $this->assertNull($this->question(2)->opens_at);

        // The scheduler tick picks it up; the competition then runs to the end, also crashing once
        // while storing the results, which the recovery command finalizes.
        $this->artisan('competitions:tick');
        $this->assertNotNull($q1->fresh()->closed_at);
        $this->assertSame(1, AnswerSubmission::count(), 'The answer given before the crash is kept; none are invented.');

        $failFinalize = true;
        Event::listen('eloquent.creating: '.ResultVersion::class, function () use (&$failFinalize) {
            if ($failFinalize) {
                $failFinalize = false;
                throw new RuntimeException('Process killed while storing results');
            }
        });
        try {
            $this->playViaHttp([]);
        } catch (RuntimeException) {
        }
        $this->assertSame(SessionStatus::Scoring, $this->session->fresh()->status);

        $this->artisan('competitions:recover')->assertSuccessful();
        $this->session->refresh();
        $this->assertSame(SessionStatus::Completed, $this->session->status);
        $this->assertSame(1, ResultVersion::query()->count());
        $this->assertSame([1, 2, 2], array_column($this->results(), 'rank'));
        $this->assertSame(10_000, $this->balance(0));
        $this->assertFinancialInvariants();
    }
}
