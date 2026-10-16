<?php

namespace Tests\Feature\Reliability;

use App\Enums\LedgerTransactionType;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Jobs\DistributePrizes;
use App\Jobs\GenerateReportExport;
use App\Jobs\SendAnnouncement;
use App\Models\LedgerTransaction;
use App\Models\ResultVersion;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Engine\CompetitionRecovery;
use App\Services\Prizes\PrizeDistributionService;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\PlaysCompetitions;
use Tests\TestCase;

class RetryIntegrityTest extends TestCase
{
    use PlaysCompetitions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['competition.prizes.payouts_enabled' => true, 'competition.paid_entries.enabled' => true]);
    }

    /**
     * Player 0 wins every question, player 1 is second, the others answer nothing.
     */
    protected function plan(): array
    {
        $plan = [];
        foreach (range(1, 5) as $q) {
            $plan[$q] = [0 => [1000, true], 1 => [1500, $q <= 3]];
        }

        return $plan;
    }

    protected function prizeCredits(): int
    {
        return LedgerTransaction::query()->where('type', LedgerTransactionType::PrizePayout)->count();
    }

    protected function balance(int $player): int
    {
        return app(WalletService::class)->getAvailableBalance($this->players[$player]);
    }

    public function test_finalization_retried_after_a_failure_produces_exactly_one_result(): void
    {
        $this->competitionWithPrizes(3, [[1, 1, 10_000]]);
        $failOnce = true;
        Event::listen('eloquent.creating: '.ResultVersion::class, function () use (&$failOnce) {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('Database connection lost while storing results');
            }
        });

        try {
            $this->playOut($this->plan());
        } catch (RuntimeException) {
        }
        $this->session->refresh();
        $this->assertSame(SessionStatus::Scoring, $this->session->status, 'Nothing was marked complete.');
        $this->assertSame(0, ResultVersion::query()->count());
        $this->assertSame(0, $this->prizeCredits());

        // The recovery run (or the tick) retries; repeating it changes nothing further.
        $this->artisan('competitions:recover')->assertSuccessful();
        $this->artisan('competitions:recover')->assertSuccessful();
        $this->artisan('competitions:tick')->assertSuccessful();

        $this->session->refresh();
        $this->assertSame(SessionStatus::Completed, $this->session->status);
        $this->assertSame(RoomStatus::Completed, $this->room->fresh()->status);
        $this->assertSame(1, ResultVersion::query()->count());
        $this->assertSame(1, $this->prizeCredits());
        $this->assertSame(10_000, $this->balance(0));
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_with_a_queue_prizes_are_paid_by_a_retry_safe_job(): void
    {
        $this->competitionWithPrizes(3, [[1, 1, 10_000], [2, 2, 4_000]]);
        config(['queue.default' => 'database']);
        Queue::fake();

        $this->playOut($this->plan());

        Queue::assertPushed(DistributePrizes::class, 1);
        $this->assertSame(0, $this->prizeCredits(), 'Not paid inside the finalization.');

        // The job runs, a worker restarts mid-way and the job is retried, and a duplicate runs too.
        $job = new DistributePrizes($this->session->id);
        $job->handle(app(PrizeDistributionService::class));
        $job->handle(app(PrizeDistributionService::class));
        (new DistributePrizes($this->session->id))->handle(app(PrizeDistributionService::class));
        $this->artisan('prizes:distribute')->assertSuccessful();

        $this->assertSame(2, $this->prizeCredits());
        $this->assertSame([10_000, 4_000], [$this->balance(0), $this->balance(1)]);
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_cancellation_refunds_are_never_repeated_and_a_lost_link_is_recovered_without_paying_twice(): void
    {
        $room = $this->openRoom(['entry_fee_minor' => 5_000, 'min_participants' => 3, 'max_participants' => 10]);
        $wallets = app(WalletService::class);
        $players = User::factory()->count(2)->create();
        foreach ($players as $player) {
            $wallets->credit($wallets->walletFor($player), 20_000, LedgerTransactionType::Deposit, 'seed:'.$player->id);
            app(RoomEntryService::class)->join($player, $room);
        }
        $this->assertSame(15_000, $wallets->getAvailableBalance($players[0]));

        app(RoomLifecycle::class)->cancel($room, null, 'Venue problem');
        $refunds = fn () => LedgerTransaction::query()->where('type', LedgerTransactionType::EntryRefund)->count();
        $this->assertSame(2, $refunds());

        // A crash after the ledger reversal but before the seat recorded it.
        $seat = RoomParticipant::query()->where('user_id', $players[0]->id)->sole();
        DB::table('room_participants')->where('id', $seat->id)->update(['refund_ledger_transaction_id' => null, 'status' => ParticipantStatus::Joined->value]);

        $issues = app(CompetitionRecovery::class)->run();
        $this->assertSame(['entry_fee_not_refunded', 'refunded'], [$issues[0]['check'], $issues[0]['action']]);
        app(CompetitionRecovery::class)->run();
        $this->artisan('competitions:recover')->assertSuccessful();

        $this->assertSame(2, $refunds(), 'The existing reversal is re-linked, never repeated.');
        $this->assertNotNull($seat->fresh()->refund_ledger_transaction_id);
        $this->assertSame(ParticipantStatus::Refunded, $seat->fresh()->status);
        $this->assertSame([20_000, 20_000], [$wallets->getAvailableBalance($players[0]), $wallets->getAvailableBalance($players[1])]);
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_recovery_reports_overdue_sessions_in_dry_run_and_repairs_them_otherwise(): void
    {
        $this->competitionWithPrizes(3, []);
        $q1 = $this->question(1);
        $this->travelTo($q1->closes_at->copy()->addMinutes(2));     // nothing ran for two minutes

        $this->artisan('competitions:recover', ['--dry-run' => true])
            ->expectsOutputToContain('would advance')->expectsOutputToContain('dry run')->assertSuccessful();
        $this->assertNull($q1->fresh()->closed_at, 'A dry run changes nothing.');

        $this->artisan('competitions:recover')->expectsOutputToContain('advanced')->assertSuccessful();
        $this->assertNotNull($q1->fresh()->closed_at);
        $this->artisan('competitions:recover')->expectsOutputToContain('Nothing to recover')->assertSuccessful();
    }

    public function test_states_that_cannot_be_derived_are_reported_not_invented(): void
    {
        $this->competitionWithPrizes(3, []);
        // An impossible state (e.g. a manual database edit): the session ended but a seat is still "playing".
        DB::table('competition_sessions')->where('id', $this->session->id)->update(['status' => SessionStatus::Aborted->value]);

        $this->artisan('competitions:recover')
            ->expectsOutputToContain('aborted_session_room_active')->expectsOutputToContain('seat_still_playing')
            ->expectsOutputToContain('need attention')->assertFailed();

        $this->assertSame(RoomStatus::InProgress, $this->room->fresh()->status, 'Left for a person to decide.');
        $this->assertSame(3, RoomParticipant::query()->where('status', ParticipantStatus::Playing)->count());
    }

    public function test_queue_configuration_matches_job_timeouts(): void
    {
        $export = new GenerateReportExport(1);
        $this->assertSame(config('competition.queues.long_connection'), $export->connection);
        $this->assertGreaterThan($export->timeout, config('queue.connections.database-long.retry_after'));
        $this->assertGreaterThan((new SendAnnouncement(1))->timeout, config('queue.connections.database-long.retry_after'));
        $this->assertGreaterThan((new DistributePrizes(1))->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_ops_status_reports_a_stopped_scheduler(): void
    {
        Cache::flush();
        $this->artisan('ops:status')->expectsOutputToContain('never ran')->assertFailed();

        $this->artisan('competitions:tick')->assertSuccessful();
        $this->artisan('ops:status')->expectsOutputToContain('Healthy')->assertSuccessful();

        $this->travel(2)->minutes();
        $this->artisan('ops:status')->assertFailed();
    }
}
