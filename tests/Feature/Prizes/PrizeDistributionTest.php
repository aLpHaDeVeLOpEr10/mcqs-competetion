<?php

namespace Tests\Feature\Prizes;

use App\Enums\LedgerTransactionType;
use App\Enums\PayoutStatus;
use App\Enums\PrizeDistributionStatus;
use App\Enums\RoomStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\AdministrativeActionException;
use App\Models\AuditLog;
use App\Models\LedgerTransaction;
use App\Models\PrizeDistribution;
use App\Models\PrizePayout;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\PrizeCredited;
use App\Services\Competition\RoomLifecycle;
use App\Services\Prizes\PrizeDistributionService;
use App\Services\Results\DisputeService;
use App\Services\Results\ResultCorrectionService;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Support\PlaysCompetitions;
use Tests\TestCase;

class PrizeDistributionTest extends TestCase
{
    use PlaysCompetitions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['competition.prizes.payouts_enabled' => true, 'competition.paid_entries.enabled' => true]);
    }

    /**
     * Player 0: 5 correct, 1 can: 4, 2: 3, 3: none.
     */
    protected function rankedPlan(): array
    {
        $plan = [];
        foreach (range(1, 5) as $q) {
            $plan[$q] = [0 => [1000, true], 1 => [1500, $q <= 4], 2 => [2000, $q <= 3], 3 => [500, false]];
        }

        return $plan;
    }

    protected function balance(int $player): int
    {
        return app(WalletService::class)->getAvailableBalance($this->players[$player]);
    }

    protected function prizeCredits(): int
    {
        return LedgerTransaction::query()->where('type', LedgerTransactionType::PrizePayout)->count();
    }

    protected function payout(int $player): ?PrizePayout
    {
        return PrizePayout::query()->whereHas('participant', fn ($q) => $q->where('user_id', $this->players[$player]->id))->first();
    }

    protected function distribute(?User $actor = null): array
    {
        return app(PrizeDistributionService::class)->distribute($this->session->id, $actor);
    }

    public function test_first_second_and_third_prizes_are_credited_after_finalization(): void
    {
        Notification::fake();
        $this->competitionWithPrizes(4, [[1, 1, 10_000], [2, 2, 5_000], [3, 3, 2_500]]);
        $this->playOut($this->rankedPlan());

        $this->assertSame([10_000, 5_000, 2_500, 0], [$this->balance(0), $this->balance(1), $this->balance(2), $this->balance(3)]);
        $this->assertSame(3, $this->prizeCredits());
        $this->assertNull($this->payout(3), 'A player without a prize has no payout record.');

        $first = $this->payout(0);
        $this->assertSame(PayoutStatus::Paid, $first->status);
        $this->assertSame(1, $first->rank);
        $this->assertSame(5, $first->correct_count);
        $this->assertSame(5_000, $first->timing_ms, 'Five correct answers at 1 s each.');
        $this->assertSame(1, $first->result_version);
        $this->assertSame([1, 1], $first->rule['positions']);
        $this->assertSame(10_000, $first->amount_minor);
        $this->assertSame(10_000, $first->net_amount_minor);
        $this->assertNotNull($first->paid_at);
        $this->assertTrue($first->ledgerTransaction->related->is($first));
        $this->assertSame('prize:'.$this->session->id.':'.$first->room_participant_id, $first->ledgerTransaction->idempotency_key);

        $this->assertSame(PrizeDistributionStatus::Paid, $this->session->prizeDistribution->fresh()->status);
        $this->assertSame(-17_500, (int) Wallet::query()->where('system_code', Wallet::SYSTEM_PRIZE_POOL)->value('balance_minor'));
        $this->assertSame(3, AuditLog::query()->where('action', 'prize.paid')->count());
        Notification::assertSentTo($this->players[0], PrizeCredited::class);
        Notification::assertNotSentTo($this->players[3], PrizeCredited::class);
        $this->assertSame([], app(WalletReconciler::class)->check());

        // Player screens: results show the payout, wallet history shows the prize credit.
        $this->actingAs($this->players[0])->get(route('competitions.results', $this->room))
            ->assertSee('Credited to your wallet')->assertSee($first->ledgerTransaction->reference);
        $this->actingAs($this->players[0])->get(route('account.transactions'))->assertSee('Prize')->assertSee('+Rs 100');
    }

    public function test_repeated_distribution_never_pays_twice(): void
    {
        $this->competitionWithPrizes(4, [[1, 1, 10_000], [2, 2, 5_000]]);
        $this->playOut($this->rankedPlan());

        foreach (range(1, 3) as $i) {
            $this->distribute();
        }
        $this->artisan('prizes:distribute')->assertSuccessful();

        $this->assertSame(2, $this->prizeCredits());
        $this->assertSame([10_000, 5_000], [$this->balance(0), $this->balance(1)]);
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_percentages_of_a_guaranteed_pool_with_rounding(): void
    {
        $this->competitionWithPrizes(4, [[1, 1, 0, 5000], [2, 2, 0, 3000], [3, 3, 0, 2000]],
            ['prize_mode' => 'percentage', 'prize_pool_basis' => 'guaranteed', 'prize_pool_minor' => 100_001]);
        $this->playOut($this->rankedPlan());

        $this->assertSame([50_000, 30_000, 20_000], [$this->balance(0), $this->balance(1), $this->balance(2)]);
        $distribution = $this->session->prizeDistribution->fresh();
        $this->assertSame(100_001, $distribution->prize_pool_minor);
        $this->assertSame(100_000, $distribution->total_amount_minor);
        $this->assertSame(1, $distribution->unallocated_minor, 'The rounding paisa is reported, not given away.');
    }

    public function test_entry_fee_pool_separates_refunds_platform_share_and_prizes(): void
    {
        $this->competitionWithPrizes(4, [[1, 1, 0, 6000], [2, 2, 0, 4000]], [
            'entry_fee_minor' => 10_000, 'prize_mode' => 'percentage', 'prize_pool_basis' => 'entry_fees', 'platform_fee_bp' => 1000,
        ]);
        $this->playOut($this->rankedPlan());

        $distribution = $this->session->prizeDistribution->fresh();
        $this->assertSame(40_000, $distribution->gross_entry_fees_minor);
        $this->assertSame(0, $distribution->refunds_minor);
        $this->assertSame(4_000, $distribution->platform_amount_minor);
        $this->assertSame(36_000, $distribution->prize_pool_minor);

        // Players paid Rs 100 each; winners receive 60 % and 40 % of the Rs 360 pool.
        $this->assertSame([21_600, 14_400, 0, 0], [$this->balance(0), $this->balance(1), $this->balance(2), $this->balance(3)]);
        $this->assertSame(4_000, (int) Wallet::query()->where('system_code', Wallet::SYSTEM_PLATFORM_REVENUE)->value('balance_minor'));
        $this->assertSame(0, (int) Wallet::query()->where('system_code', Wallet::SYSTEM_PRIZE_POOL)->value('balance_minor'));
        $this->assertSame(1, LedgerTransaction::query()->where('type', LedgerTransactionType::PlatformFee)->count());

        $this->distribute();
        $this->assertSame(1, LedgerTransaction::query()->where('type', LedgerTransactionType::PlatformFee)->count(), 'The platform share moves once.');
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_tied_players_share_the_pooled_places_deterministically(): void
    {
        $this->competitionWithPrizes(3, [[1, 1, 10_001], [2, 2, 5_000]]);
        $plan = [];
        foreach (range(1, 5) as $q) {
            $plan[$q] = [0 => [1000, true], 1 => [1000, true], 2 => [1000, $q === 1]];
        }
        $this->playOut($plan);

        // Players 0 and 1 share rank 1: places 1–2 pooled (Rs 150.01), floor(15001 / 2) = 7500 each.
        $this->assertSame([1, 1], [$this->payout(0)->rank, $this->payout(1)->rank]);
        $this->assertSame([7_500, 7_500, 0], [$this->balance(0), $this->balance(1), $this->balance(2)]);
        $this->assertSame(2, $this->payout(0)->rule['tied_players']);
        $this->assertSame(15_001, $this->payout(0)->rule['group_pool_minor']);
        $this->assertSame(1, $this->payout(0)->rule['group_remainder_minor']);
        $this->assertSame(1, $this->session->prizeDistribution->fresh()->unallocated_minor);
    }

    public function test_cancelled_competitions_pay_nothing(): void
    {
        $this->startingCompetition(3);
        $this->room->prizeTiers()->create(['rank_from' => 1, 'rank_to' => 1, 'amount_minor' => 10_000]);
        app(RoomLifecycle::class)->cancel($this->room, null, 'Cancelled before start');

        $this->assertSame('none', $this->distribute()['status']);
        $this->assertSame(0, PrizeDistribution::query()->count());
        $this->assertSame(0, $this->prizeCredits());
        $this->assertSame(RoomStatus::Cancelled, $this->room->fresh()->status);
    }

    public function test_payouts_are_held_while_a_dispute_is_open_and_paid_after_it_is_resolved(): void
    {
        config(['competition.prizes.payouts_enabled' => false]);
        $this->competitionWithPrizes(4, [[1, 1, 10_000]]);
        $this->playOut($this->rankedPlan());
        $this->assertSame(PayoutStatus::Held, $this->payout(0)->status, 'Disabled payouts keep entitlements on hold.');
        $this->assertSame(0, $this->prizeCredits());

        config(['competition.prizes.payouts_enabled' => true]);
        $dispute = app(DisputeService::class)->raise($this->players[2], $this->room, 'I think question 3 had two correct answers.');

        $result = $this->distribute();
        $this->assertSame('held', $result['status']);
        $this->assertSame('A result dispute is open.', $this->payout(0)->status_reason);
        $this->assertSame(PrizeDistributionStatus::Held, $this->session->prizeDistribution->fresh()->status);
        $this->assertSame(0, $this->prizeCredits());

        $admin = User::factory()->role(UserRole::Admin)->create();
        app(DisputeService::class)->reject($admin, $dispute, 'The answer key was checked and is correct.');
        $this->artisan('prizes:distribute')->assertSuccessful();

        $this->assertSame(PayoutStatus::Paid, $this->payout(0)->status);
        $this->assertSame(10_000, $this->balance(0));
    }

    public function test_competitions_requiring_review_are_paid_only_after_finance_approval(): void
    {
        $this->competitionWithPrizes(4, [[1, 1, 10_000], [2, 2, 5_000]], ['prize_review_required' => true]);
        $this->playOut($this->rankedPlan());

        $this->assertSame(PayoutStatus::Held, $this->payout(0)->status);
        $this->assertSame('Waiting for finance review.', $this->payout(0)->status_reason);
        $this->assertSame(0, $this->prizeCredits());

        $route = route('admin.payouts.approve', $this->session);
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())->post($route, ['reason' => 'Looks right', 'confirm' => '1'])->assertForbidden();

        $finance = User::factory()->role(UserRole::FinanceManager)->create();
        $this->actingAs($finance)->post($route, ['reason' => 'Checked the ranking', 'confirm' => '1'])->assertSessionHas('success');

        $this->assertSame([10_000, 5_000], [$this->balance(0), $this->balance(1)]);
        $this->assertSame($finance->id, $this->session->prizeDistribution->fresh()->approved_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'prizes.approved')->where('actor_id', $finance->id)->count());
    }

    public function test_a_failure_while_crediting_rolls_back_and_the_next_run_pays_once(): void
    {
        config(['competition.prizes.payouts_enabled' => false]);
        $this->competitionWithPrizes(4, [[1, 1, 10_000]]);
        $this->playOut($this->rankedPlan());
        config(['competition.prizes.payouts_enabled' => true]);

        $fail = true;
        LedgerTransaction::creating(function (LedgerTransaction $tx) use (&$fail) {
            if ($fail && $tx->type === LedgerTransactionType::PrizePayout) {
                throw new RuntimeException('Simulated database failure');
            }
        });

        try {
            $this->distribute();
            $this->fail('The failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated database failure', $e->getMessage());
        }

        $this->assertSame(PayoutStatus::Processing, $this->payout(0)->status, 'Claimed but not paid: the credit was rolled back.');
        $this->assertNull($this->payout(0)->ledger_transaction_id);
        $this->assertSame(0, $this->balance(0));

        $fail = false;
        $this->distribute();
        $this->distribute();

        $this->assertSame(PayoutStatus::Paid, $this->payout(0)->status);
        $this->assertSame(10_000, $this->balance(0));
        $this->assertSame(1, $this->prizeCredits());
    }

    public function test_suspended_winners_are_held_while_others_are_paid(): void
    {
        config(['competition.prizes.payouts_enabled' => false]);
        $this->competitionWithPrizes(4, [[1, 1, 10_000], [2, 2, 5_000]]);
        $this->playOut($this->rankedPlan());
        config(['competition.prizes.payouts_enabled' => true]);
        $this->players[0]->forceFill(['status' => UserStatus::Suspended])->save();

        $this->distribute();

        $this->assertSame(PayoutStatus::Held, $this->payout(0)->status);
        $this->assertSame(PayoutStatus::Paid, $this->payout(1)->status);
        $this->assertSame([0, 5_000], [$this->balance(0), $this->balance(1)]);
    }

    public function test_tax_withholding_is_deducted_and_recorded_when_configured(): void
    {
        config(['competition.prizes.tax_withholding_bp' => 1000]);
        $this->competitionWithPrizes(4, [[1, 1, 10_001]]);
        $this->playOut($this->rankedPlan());

        $payout = $this->payout(0);
        $this->assertSame(10_001, $payout->amount_minor);
        $this->assertSame(1_000, $payout->tax_withheld_minor, '10 % of 100.01, rounded down.');
        $this->assertSame(9_001, $payout->net_amount_minor);
        $this->assertSame(9_001, $this->balance(0));
        $this->assertSame(1_000, (int) Wallet::query()->where('system_code', Wallet::SYSTEM_TAX_WITHHOLDING)->value('balance_minor'));
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_paid_prizes_are_immutable_block_corrections_and_can_only_be_reversed(): void
    {
        $this->competitionWithPrizes(4, [[1, 1, 10_000]]);
        $this->playOut($this->rankedPlan());
        $payout = $this->payout(0);

        try {
            DB::table('prize_payouts')->where('id', $payout->id)->update(['amount_minor' => 99_999]);
            $this->fail('A paid payout must not be editable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('settled payouts cannot be changed', $e->getMessage());
        }

        $admin = User::factory()->role(UserRole::Admin)->create();
        try {
            app(ResultCorrectionService::class)->disqualify($admin, $this->session, $payout->participant, 'Late review');
            $this->fail('Corrections must be refused once prizes are paid.');
        } catch (AdministrativeActionException $e) {
            $this->assertStringContainsString('Reverse the paid prizes first', $e->getMessage());
        }

        $this->actingAs($admin)->post(route('admin.payouts.reverse', $payout), ['reason' => 'Fraud review', 'confirm' => '1'])->assertForbidden();
        $finance = User::factory()->role(UserRole::FinanceManager)->create();
        $this->actingAs($finance)->post(route('admin.payouts.reverse', $payout), ['reason' => 'Fraud review', 'confirm' => '1'])->assertSessionHas('success');

        $payout->refresh();
        $this->assertSame(PayoutStatus::Reversed, $payout->status);
        $this->assertSame(0, $this->balance(0));
        $this->assertSame($payout->ledger_transaction_id, $payout->reversalLedgerTransaction->reverses_transaction_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'prize.reversed')->count());

        $this->expectException(QueryException::class);
        DB::table('prize_payouts')->where('id', $payout->id)->update(['status' => 'paid']);
    }

    public function test_staff_can_hold_release_and_retry_with_audit(): void
    {
        config(['competition.prizes.payouts_enabled' => false]);
        $this->competitionWithPrizes(4, [[1, 1, 10_000], [2, 2, 5_000]]);
        $this->playOut($this->rankedPlan());
        config(['competition.prizes.payouts_enabled' => true]);
        $finance = User::factory()->role(UserRole::FinanceManager)->create();

        // A manual hold survives automatic runs.
        $this->payout(0)->forceFill(['status' => PayoutStatus::Pending])->save();
        $this->actingAs($finance)->post(route('admin.payouts.hold', $this->payout(0)), ['reason' => 'Identity check', 'confirm' => '1'])->assertSessionHas('success');
        $this->distribute();
        $this->assertSame(PayoutStatus::Held, $this->payout(0)->status);
        $this->assertSame(PayoutStatus::Paid, $this->payout(1)->status);

        $this->actingAs($finance)->post(route('admin.payouts.release', $this->payout(0)), ['reason' => 'Identity verified', 'confirm' => '1']);
        $this->assertSame(PayoutStatus::Paid, $this->payout(0)->status);
        $this->assertSame(10_000, $this->balance(0));

        $this->assertSame(['prize.held', 'prize.released'], AuditLog::query()->whereIn('action', ['prize.held', 'prize.released'])->orderBy('id')->pluck('action')->all());

        // Validation: a reason and confirmation are required; staff cannot edit amounts anywhere.
        $this->actingAs($finance)->post(route('admin.payouts.hold', $this->payout(1)), [])->assertSessionHasErrorsIn('payout-'.$this->payout(1)->id, ['reason', 'confirm']);
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'payouts') && in_array('PUT', $r->methods(), true)));

        // A failed payout is retried only by staff, and then paid once.
        $this->competitionWithPrizes(2, [[1, 1, 3_000]]);
        config(['competition.prizes.payouts_enabled' => false]);
        $this->playOut([1 => [0 => [1000, true]]]);
        config(['competition.prizes.payouts_enabled' => true]);
        $failed = $this->payout(0);
        $failed->forceFill(['status' => PayoutStatus::Failed, 'status_reason' => 'Simulated provider-side refusal', 'failed_at' => now()])->save();

        $this->distribute();
        $this->assertSame(PayoutStatus::Failed, $failed->fresh()->status, 'Automatic runs do not retry failed payouts.');

        $this->actingAs($finance)->post(route('admin.payouts.retry', $failed), ['reason' => 'Cause fixed', 'confirm' => '1'])->assertSessionHas('success');
        $this->assertSame(PayoutStatus::Paid, $failed->fresh()->status);
        $this->assertSame(3_000, $this->balance(0));
        $this->assertSame(1, AuditLog::query()->where('action', 'prize.retried')->count());

    }

    public function test_finance_pages_show_payout_details(): void
    {
        $this->competitionWithPrizes(4, [[1, 1, 10_000]]);
        $this->playOut($this->rankedPlan());
        $payout = $this->payout(0);
        $finance = User::factory()->role(UserRole::FinanceManager)->create();

        $this->actingAs($finance)->get(route('admin.payouts.index'))->assertOk()
            ->assertSee($this->room->name)->assertSee($this->players[0]->display_name)->assertSee('#1')->assertSee('Rs 100')->assertSee($payout->ledgerTransaction->reference);
        $this->actingAs($finance)->get(route('admin.payouts.show', $this->session))->assertOk()
            ->assertSee('Prize pool')->assertSee('5 correct')->assertSee('Paid')->assertSee('Reverse the prize of');

        $this->actingAs(User::factory()->role(UserRole::ContentManager)->create())->get(route('admin.payouts.index'))->assertForbidden();
        $this->actingAs($this->players[0])->get(route('admin.payouts.show', $this->session))->assertForbidden();
    }
}
