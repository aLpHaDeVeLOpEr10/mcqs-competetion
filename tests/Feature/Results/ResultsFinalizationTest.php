<?php

namespace Tests\Feature\Results;

use App\Enums\PayoutStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Events\Competition\ResultsPublished;
use App\Models\AuditLog;
use App\Models\ParticipantResult;
use App\Models\PrizePayout;
use App\Models\ResultVersion;
use App\Models\User;
use App\Services\Results\ResultsCalculator;
use App\Services\Results\ResultsFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\PlaysCompetitions;
use Tests\TestCase;

class ResultsFinalizationTest extends TestCase
{
    use PlaysCompetitions, RefreshDatabase;

    protected function rank(int $playerIndex): ParticipantResult
    {
        return ParticipantResult::query()->whereHas('participant', fn ($q) => $q->where('user_id', $this->players[$playerIndex]->id))->sole();
    }

    /** Players: 0 = all correct, fast; 1 = all correct, slower; 2 = all wrong. */
    protected function standardPlan(): array
    {
        $plan = [];
        foreach (range(1, 5) as $p) {
            $plan[$p] = [0 => [1000, true], 1 => [2000, true], 2 => [500, false]];
        }

        return $plan;
    }

    public function test_results_are_finalized_published_and_the_room_completed_after_the_last_question(): void
    {
        Event::fake([ResultsPublished::class]);
        $this->competitionWithPrizes(3, [[1, 1, 100_000], [2, 2, 50_000]]);

        $this->playOut($this->standardPlan());

        $this->assertSame(SessionStatus::Completed, $this->session->status);
        $this->assertSame(RoomStatus::Completed, $this->room->status);
        $this->assertNotNull($this->session->results_published_at);

        $winner = $this->rank(0);
        $this->assertSame([1, 5, 5, 5_000], [$winner->rank, $winner->score, $winner->correct_count, $winner->correct_response_time_ms]);
        $this->assertTrue($winner->is_winner);
        $this->assertSame(100_000, $winner->prize_amount_minor);

        $second = $this->rank(1);
        $this->assertSame([2, 10_000, 50_000], [$second->rank, $second->correct_response_time_ms, $second->prize_amount_minor]);

        $last = $this->rank(2);
        $this->assertSame([3, 0, 5, false], [$last->rank, $last->score, $last->answered_count, $last->prize_eligible]);

        $version = ResultVersion::sole();
        $this->assertSame('correct_count_v1', $version->scoring_policy);
        $this->assertSame('fastest_correct', $version->tie_break_policy);
        $this->assertSame(hash('sha256', json_encode($version->inputs)), $version->inputs_hash);
        $this->assertSame(1, AuditLog::where('action', 'results.finalized')->count());
        Event::assertDispatched(ResultsPublished::class, fn ($e) => $e->version === 1 && ! str_contains(json_encode($e->broadcastWith()), 'correct'));
    }

    public function test_prizes_are_entitlements_only_and_held_while_payouts_are_disabled(): void
    {
        $this->competitionWithPrizes(3, [[1, 1, 100_000], [2, 2, 50_000]]);
        $this->playOut($this->standardPlan());

        $payouts = PrizePayout::query()->orderBy('rank')->get();
        $this->assertSame([[1, 100_000], [2, 50_000]], $payouts->map(fn ($p) => [$p->rank, $p->amount_minor])->all());
        $this->assertTrue($payouts->every(fn ($p) => $p->status === PayoutStatus::Held && $p->ledger_transaction_id === null));
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame(150_000, $this->session->prizeDistribution->total_amount_minor);
    }

    public function test_correctness_comes_from_the_snapshot_not_the_stored_flag(): void
    {
        $this->competitionWithPrizes(2, []);
        $this->playOut([1 => [0 => [1000, false], 1 => [1000, true]]]);
        $this->assertSame([2, 1], [$this->rank(0)->rank, $this->rank(1)->rank]);

        // Even if the stored flag were tampered with, recalculation uses the snapshot answer key.
        DB::table('answer_submissions')->update(['is_correct' => true, 'points_awarded' => 99]);
        app(ResultsCalculator::class)->calculate($this->room, $this->session, 'check', null);

        $this->assertSame(0, $this->rank(0)->score);
        $this->assertSame(1, $this->rank(1)->score);
    }

    public function test_prize_configuration_is_frozen_at_preparation(): void
    {
        $this->competitionWithPrizes(2, [[1, 1, 10_000]]);
        DB::table('room_prize_tiers')->update(['amount_minor' => 999_999]); // later tampering

        $this->playOut([1 => [0 => [1000, true]]]);

        $this->assertSame(10_000, $this->rank(0)->prize_amount_minor);
    }

    public function test_finalization_is_idempotent(): void
    {
        $this->competitionWithPrizes(2, [[1, 1, 10_000]]);
        $this->playOut([1 => [0 => [1000, true]]]);

        foreach (range(1, 3) as $i) {
            $this->assertSame(1, app(ResultsFinalizer::class)->finalize($this->session->id)->version);
        }
        $this->artisan('competitions:tick')->assertSuccessful();

        $this->assertSame(1, ResultVersion::count());
        $this->assertSame(1, PrizePayout::count());
        $this->assertSame(1, AuditLog::where('action', 'results.finalized')->count());
    }

    public function test_a_live_competition_is_not_finalized_early(): void
    {
        $this->competitionWithPrizes(2, []);

        $this->assertNull(app(ResultsFinalizer::class)->finalize($this->session->id));
        $this->assertSame(SessionStatus::InProgress, $this->session->fresh()->status);
        $this->assertSame(0, ResultVersion::count());
    }

    public function test_players_who_answer_nothing_tie_last_and_get_no_prize(): void
    {
        $this->competitionWithPrizes(3, [[1, 3, 10_000]]);
        $this->playOut([]);

        $ranks = ParticipantResult::query()->pluck('rank')->all();
        $this->assertSame([1, 1, 1], $ranks, 'Everyone shares rank 1; no winner is invented.');
        $this->assertSame(0, PrizePayout::count());
        $this->assertFalse(ParticipantResult::query()->where('is_winner', true)->exists());
        $this->assertSame(30_000, $this->session->prizeDistribution->calculation['unallocated_minor']);
    }

    public function test_tied_winners_share_pooled_prizes(): void
    {
        $this->competitionWithPrizes(3, [[1, 1, 100_000], [2, 2, 50_000]]);
        $this->playOut([1 => [0 => [1500, true], 1 => [1500, true], 2 => [100, false]]]);

        $this->assertSame([1, 1], [$this->rank(0)->rank, $this->rank(1)->rank]);
        $this->assertSame([75_000, 75_000], [$this->rank(0)->prize_amount_minor, $this->rank(1)->prize_amount_minor]);
        $this->assertTrue($this->rank(0)->is_winner && $this->rank(1)->is_winner);
    }

    public function test_results_can_be_held_for_manual_publication(): void
    {
        config(['competition.results.auto_publish' => false]);
        $this->competitionWithPrizes(2, []);
        $this->playOut([1 => [0 => [1000, true]]]);

        $this->assertSame(SessionStatus::Completed, $this->session->status);
        $this->assertNull($this->session->results_published_at);
        $this->get(route('competitions.results', $this->room))->assertNotFound();

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.results.publish', $this->session))->assertSessionHas('success');
        $this->get(route('competitions.results', $this->room))->assertOk();
    }
}
