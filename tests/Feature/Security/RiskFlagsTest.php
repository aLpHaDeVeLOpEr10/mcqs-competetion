<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Logging\RedactSensitiveData;
use App\Models\AuditLog;
use App\Models\PaymentTransaction;
use App\Models\RiskFlag;
use App\Models\User;
use App\Services\Security\RiskMonitor;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\UsesFakeGateway;
use Tests\TestCase;

class RiskFlagsTest extends TestCase
{
    use RefreshDatabase, UsesFakeGateway;

    protected function risk(): RiskMonitor
    {
        return app(RiskMonitor::class);
    }

    public function test_signals_are_counted_per_day_and_open_only_at_the_threshold(): void
    {
        $user = User::factory()->create();

        $first = $this->risk()->signal('rapid_answers', $user);
        $this->assertSame(['watching', 1, 3], [$first->status, $first->occurrences, $first->threshold]);

        $this->risk()->signal('rapid_answers', $user);
        $third = $this->risk()->signal('rapid_answers', $user);
        $this->assertSame(['open', 3], [$third->status, $third->occurrences]);
        $this->assertSame(1, RiskFlag::query()->count(), 'Repeats are counted on one row, not duplicated.');

        // A new day starts a new count; another user has their own.
        $this->risk()->signal('rapid_answers', User::factory()->create());
        $this->travel(1)->days();
        $this->risk()->signal('rapid_answers', $user);
        $this->assertSame(3, RiskFlag::query()->count());

        // Threshold 1 opens at once; credential-like details are never stored.
        $flag = $this->risk()->signal('wallet_discrepancy', $user, null, ['token' => 'abc', 'check' => 'x']);
        $this->assertSame(['open', 'high'], [$flag->status, $flag->severity]);
        $this->assertSame(['token' => '[removed]', 'check' => 'x'], $flag->details);
    }

    public function test_a_reviewed_flag_stays_closed_and_signals_never_break_the_operation(): void
    {
        $user = User::factory()->create();
        $flag = $this->risk()->signal('multiple_sessions', $user);
        $flag->forceFill(['status' => 'dismissed'])->save();
        $this->assertSame('dismissed', $this->risk()->signal('multiple_sessions', $user)->status);

        $this->assertNull($this->risk()->signal('no_such_type', $user));
    }

    public function test_deferred_signals_are_dropped_when_the_operation_rolls_back(): void
    {
        $user = User::factory()->create();

        try {
            DB::transaction(function () use ($user) {
                $this->risk()->later('withdrawals_cancelled', $user);
                throw new RuntimeException('failed');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, RiskFlag::query()->count());

        DB::transaction(fn () => $this->risk()->later('withdrawals_cancelled', $user));
        $this->assertSame(1, RiskFlag::query()->count());
    }

    public function test_rejected_payment_notifications_raise_review_signals_without_changing_anything(): void
    {
        $this->enableFakeGateway();
        $player = User::factory()->create();
        $payment = $this->startDeposit($player, 50_000);
        $this->fake()->setProviderStatus($payment->reference, 'succeeded');
        $state = $this->providerState($payment);

        $this->deliver($this->fake()->notification($state, ['amount' => '5000000']))->assertStatus(400);
        $this->deliver($this->fake()->notification($state, ['currency' => 'USD']))->assertStatus(400);
        $this->deliver($this->fake()->notification($state, ['provider_reference' => 'FKOTHERPAYMENT']))->assertStatus(400);
        $this->deliver(['reference' => 'nothing', 'signature' => 'forged'])->assertStatus(400);

        $failed = RiskFlag::query()->where('type', 'payment_verification_failed')->sole();
        $this->assertSame([$player->id, 2, 'open'], [$failed->user_id, $failed->occurrences, $failed->status]);
        $this->assertTrue($failed->subject->is($payment));

        $conflict = RiskFlag::query()->where('type', 'provider_reference_conflict')->sole();
        $this->assertSame(['high', 'open'], [$conflict->severity, $conflict->status]);

        $anonymous = RiskFlag::query()->where('type', 'invalid_payment_callback')->sole();
        $this->assertNull($anonymous->user_id);
        $this->assertSame('watching', $anonymous->status, 'One stray notification is not a review item.');

        $this->assertSame(0, app(WalletService::class)->getAvailableBalance($player));
        $this->assertSame('pending', PaymentTransaction::query()->sole()->status->value);
    }

    public function test_a_wallet_discrepancy_opens_a_high_severity_flag_for_its_owner(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->walletFor($user);
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_minor' => 999_999]);

        $this->artisan('wallets:reconcile')->assertFailed();

        $flag = RiskFlag::query()->sole();
        $this->assertSame(['wallet_discrepancy', 'high', 'open', $user->id], [$flag->type, $flag->severity, $flag->status, $flag->user_id]);
        $this->assertSame(999_999, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'), 'Nothing is corrected automatically.');
    }

    public function test_only_risk_reviewers_can_open_the_review_page(): void
    {
        $this->risk()->signal('wallet_discrepancy', User::factory()->create());

        $this->get(route('admin.risk.index'))->assertRedirect(route('admin.login'));
        foreach ([UserRole::Player, UserRole::ContentManager, UserRole::CompetitionManager] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get(route('admin.risk.index'))->assertForbidden();
        }
        foreach ([UserRole::FinanceManager, UserRole::Admin, UserRole::SuperAdmin] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get(route('admin.risk.index'))
                ->assertOk()->assertSee('signals for review, not proof', false)->assertSee('Wallet balance disagrees with the ledger');
        }
    }

    public function test_reviewing_records_the_decision_once_and_is_audited(): void
    {
        $subject = User::factory()->create();
        $flag = $this->risk()->signal('withdrawal_without_play', $subject);
        $staff = User::factory()->role(UserRole::FinanceManager)->create();

        $this->actingAs($staff)->post(route('admin.risk.review', $flag), ['decision' => 'dismissed', 'note' => ''])
            ->assertSessionHasErrors('note');
        $this->actingAs($staff)->post(route('admin.risk.review', $flag), ['decision' => 'banned', 'note' => 'Checked history'])
            ->assertSessionHasErrors('decision');

        $this->actingAs($staff)->post(route('admin.risk.review', $flag), ['decision' => 'dismissed', 'note' => 'Regular player, deposit was a mistake'])
            ->assertSessionHas('success');
        $flag->refresh();
        $this->assertSame(['dismissed', $staff->id], [$flag->status, $flag->reviewed_by]);
        $this->assertSame('risk.dismissed', AuditLog::query()->where('auditable_type', $flag->getMorphClass())->sole()->action);

        $this->actingAs($staff)->post(route('admin.risk.review', $flag), ['decision' => 'reviewed', 'note' => 'Second attempt'])
            ->assertSessionHas('error');
        $this->assertSame('dismissed', $flag->fresh()->status);

        // Reviewing never changes the account.
        $this->assertSame($subject->status, $subject->fresh()->status);
    }

    public function test_log_context_is_redacted(): void
    {
        $this->assertSame(
            ['password' => '[removed]', 'nested' => ['account_number' => '[removed]', 'amount_minor' => 5], 'reference' => 'ABC'],
            RedactSensitiveData::redact(['password' => 'hunter2', 'nested' => ['account_number' => '0300', 'amount_minor' => 5], 'reference' => 'ABC']),
        );
        $this->assertContains(RedactSensitiveData::class, config('logging.channels.single.tap'));
    }
}
