<?php

namespace Tests\Feature\Withdrawals;

use App\Enums\LedgerTransactionType;
use App\Enums\PayoutMethod;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\WalletOperationException;
use App\Models\AuditLog;
use App\Models\LedgerTransaction;
use App\Models\PaymentCallback;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use App\Services\Withdrawals\PayoutDestinationService;
use App\Services\Withdrawals\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UsesWithdrawals;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use RefreshDatabase, UsesWithdrawals;

    protected User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWithdrawals();
        $this->finance = User::factory()->role(UserRole::FinanceManager)->create();
    }

    protected function service(): WithdrawalService
    {
        return app(WithdrawalService::class);
    }

    protected function requested(User $user, int $amount = 100_000): Withdrawal
    {
        return $this->service()->request($user, $user->payoutDestinations()->first() ?? $this->destination($user), $amount, (string) Str::uuid());
    }

    protected function approved(User $user, int $amount = 100_000): Withdrawal
    {
        $w = $this->requested($user, $amount);
        $this->service()->startReview($this->finance, $w);

        return $this->service()->approve($this->finance, $w, 'Checked the account history');
    }

    protected function releases(): int
    {
        return LedgerTransaction::query()->where('type', LedgerTransactionType::Reversal)->count();
    }

    public function test_a_valid_request_holds_the_funds_and_returns_a_pending_reference(): void
    {
        $this->enableWithdrawals(['competition.withdrawals.fee_flat_minor' => 1_000, 'competition.withdrawals.fee_bp' => 100]);
        $user = $this->funded(300_000);
        $destination = $this->destination($user);

        // Review step shows the amount, the disclosed fee (Rs 10 + 1 %) and the total.
        $this->actingAs($user)->post(route('account.withdrawals.review'), ['destination_id' => $destination->id, 'amount' => '1,000'])
            ->assertOk()->assertSee('Rs 1,000')->assertSee('Rs 20')->assertSee('Rs 1,020')->assertSee('JazzCash •••• 4567');

        $token = (string) Str::uuid();
        $response = $this->actingAs($user)->post(route('account.withdrawals.store'), ['destination_id' => $destination->id, 'amount' => '1000', 'token' => $token, 'confirm' => '1']);

        $withdrawal = Withdrawal::query()->sole();
        $response->assertRedirect(route('account.withdrawals.show', $withdrawal->reference));
        $this->assertSame(WithdrawalStatus::Requested, $withdrawal->status);
        $this->assertSame([100_000, 2_000], [$withdrawal->amount_minor, $withdrawal->fee_minor]);
        $this->assertSame(198_000, $this->balance($user), 'Amount and fee are held from the wallet at once.');
        $this->assertSame(LedgerTransactionType::Withdrawal, $withdrawal->holdTransaction->type);
        $this->assertTrue($withdrawal->holdTransaction->related->is($withdrawal));
        $this->assertSame(2_000, (int) Wallet::query()->where('system_code', Wallet::SYSTEM_PLATFORM_REVENUE)->value('balance_minor'));

        // Masked everywhere; the full number is never in the record, the audit log or the page.
        $this->assertSame('4567', $withdrawal->destination_snapshot['last4']);
        $this->assertStringNotContainsString(self::MOBILE, json_encode($withdrawal->destination_snapshot));
        $this->assertStringNotContainsString(self::MOBILE, AuditLog::query()->get()->toJson());
        $this->actingAs($user)->get(route('account.withdrawals.show', $withdrawal->reference))->assertOk()
            ->assertSee($withdrawal->reference)->assertSee('Requested')->assertDontSee(self::MOBILE);
        $this->assertSame('withdrawal_updated', $user->notifications()->where('data->type', 'withdrawal_updated')->first()->data['type']);

        // The same confirmation submitted twice creates one withdrawal.
        $this->actingAs($user)->post(route('account.withdrawals.store'), ['destination_id' => $destination->id, 'amount' => '1000', 'token' => $token, 'confirm' => '1']);
        $this->assertSame(1, Withdrawal::query()->count());
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_insufficient_funds_take_nothing(): void
    {
        $user = $this->funded(99_999);
        $destination = $this->destination($user);

        $this->actingAs($user)->post(route('account.withdrawals.store'), ['destination_id' => $destination->id, 'amount' => '1000', 'token' => (string) Str::uuid(), 'confirm' => '1'])
            ->assertSessionHas('error', 'Your wallet balance is lower than the amount plus fee. Nothing was withdrawn.');

        $this->assertSame(0, Withdrawal::query()->count());
        $this->assertSame(99_999, $this->balance($user));
    }

    public function test_eligibility_rules(): void
    {
        $user = $this->funded(10_000_000);
        $destination = $this->destination($user);
        $try = fn (int $amount) => $this->service()->request($user, $destination, $amount, (string) Str::uuid());
        $refused = function (callable $attempt, string $message) {
            try {
                $attempt();
                $this->fail("Expected refusal: {$message}");
            } catch (AdministrativeActionException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };

        $refused(fn () => $try(49_999), 'Enter an amount between Rs 500 and Rs 25,000');
        $refused(fn () => $try(2_500_001), 'Enter an amount between Rs 500 and Rs 25,000');

        $try(2_500_000);
        $refused(fn () => $try(50_000), 'You already have a withdrawal in progress');

        config(['competition.withdrawals.max_pending' => 5]);
        $try(2_500_000);
        $refused(fn () => $try(50_000), 'per 24 hours');

        // A brand-new destination is in its cooling-off period; someone else's is never usable.
        $fresh = app(PayoutDestinationService::class)->add($user, PayoutMethod::MobileWallet, 'EASYPAISA', 'Holder', '03119876543');
        $refused(fn () => $this->service()->request($user, $fresh, 50_000, (string) Str::uuid()), 'added recently');
        $other = $this->funded(500_000);
        $refused(fn () => $this->service()->request($other, $destination, 50_000, (string) Str::uuid()), 'Choose one of your payout accounts');

        // Restricted accounts and disabled withdrawals.
        $restricted = $this->funded(500_000, ['status' => UserStatus::Restricted]);
        $refused(fn () => $this->service()->request($restricted, $this->destination($restricted, '03211111111'), 50_000, (string) Str::uuid()), 'cannot make withdrawals');
        config(['competition.withdrawals.enabled' => false]);
        $refused(fn () => $try(50_000), 'not available');
    }

    public function test_held_funds_cannot_be_spent_again(): void
    {
        config(['competition.withdrawals.max_pending' => 5]);
        $user = $this->funded(150_000);
        $this->requested($user, 100_000);

        $this->assertSame(50_000, $this->balance($user));
        try {
            $this->requested($user, 60_000);
            $this->fail('Held money must not be withdrawable twice.');
        } catch (WalletOperationException $e) {
            $this->assertSame(WalletOperationException::INSUFFICIENT_FUNDS, $e->reason);
        }
        $this->assertSame(1, Withdrawal::query()->count());
    }

    public function test_approval_rejection_and_cancellation_release_funds_exactly_once(): void
    {
        config(['competition.withdrawals.max_pending' => 5]);
        $user = $this->funded(500_000);

        // Rejected: funds back, once.
        $rejected = $this->requested($user);
        $this->service()->startReview($this->finance, $rejected);
        $this->actingAs($this->finance)->post(route('admin.withdrawals.reject', $rejected), ['reason' => 'Name does not match', 'confirm' => '1'])->assertSessionHas('success');
        $rejected->refresh();
        $this->assertSame(WithdrawalStatus::Rejected, $rejected->status);
        $this->assertSame('Name does not match', $rejected->rejection_reason);
        $this->assertSame($rejected->hold_ledger_transaction_id, $rejected->releaseTransaction->reverses_transaction_id);
        $this->assertSame(500_000, $this->balance($user));
        $this->actingAs($this->finance)->post(route('admin.withdrawals.reject', $rejected), ['reason' => 'Again please', 'confirm' => '1'])->assertSessionHas('error');
        $this->assertSame(1, $this->releases());

        // Cancelled by the player while still requested.
        $cancelled = $this->requested($user);
        $this->actingAs($user)->post(route('account.withdrawals.cancel', $cancelled->reference))->assertSessionHas('success');
        $this->assertSame(WithdrawalStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(500_000, $this->balance($user));
        $this->assertSame(2, $this->releases());

        // Approved: still held; cannot be cancelled by the player any more.
        $approved = $this->approved($user);
        $this->assertSame($this->finance->id, $approved->approved_by);
        $this->assertSame(400_000, $this->balance($user));
        $this->actingAs($user)->post(route('account.withdrawals.cancel', $approved->reference))->assertSessionHas('error');

        // Every change is audited with its actor and reason; the records remain.
        $this->assertSame(['withdrawal.requested', 'withdrawal.under_review', 'withdrawal.rejected'],
            AuditLog::query()->where('auditable_id', $rejected->id)->where('auditable_type', $rejected->getMorphClass())->orderBy('id')->pluck('action')->all());
        $this->assertSame(3, Withdrawal::query()->count());
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_a_paid_payout_is_final_and_duplicate_callbacks_change_nothing(): void
    {
        $user = $this->funded(300_000);
        $withdrawal = $this->service()->initiatePayout($this->finance, $this->approved($user));
        $this->assertSame(WithdrawalStatus::Processing, $withdrawal->status);
        $this->assertNotNull($withdrawal->provider_reference);

        $notification = $this->payouts()->complete($withdrawal->reference, 'paid');
        $this->post(route('withdrawals.callback', 'fake'), $notification)->assertOk();
        $this->post(route('withdrawals.callback', 'fake'), $notification)->assertOk();

        $withdrawal->refresh();
        $this->assertSame(WithdrawalStatus::Paid, $withdrawal->status);
        $this->assertNotNull($withdrawal->paid_at);
        $this->assertSame(200_000, $this->balance($user));
        $this->assertSame(0, $this->releases());
        $this->assertSame([PaymentCallback::APPLIED, PaymentCallback::DUPLICATE], PaymentCallback::query()->orderBy('id')->pluck('outcome')->all());

        // The provider later reports the money came back: released once.
        $returned = $this->payouts()->complete($withdrawal->reference, 'reversed');
        $this->post(route('withdrawals.callback', 'fake'), $returned)->assertOk();
        $this->post(route('withdrawals.callback', 'fake'), $returned)->assertOk();
        $this->assertSame(WithdrawalStatus::Reversed, $withdrawal->fresh()->status);
        $this->assertSame(300_000, $this->balance($user));
        $this->assertSame(1, $this->releases());
    }

    public function test_a_failed_payout_releases_the_funds_once(): void
    {
        $user = $this->funded(300_000);
        $withdrawal = $this->service()->initiatePayout($this->finance, $this->approved($user));
        $failed = $this->payouts()->complete($withdrawal->reference, 'failed');

        foreach (range(1, 3) as $i) {
            $this->post(route('withdrawals.callback', 'fake'), $failed)->assertOk();
        }
        $this->artisan('withdrawals:reconcile')->assertSuccessful();

        $this->assertSame(WithdrawalStatus::Failed, $withdrawal->fresh()->status);
        $this->assertSame(300_000, $this->balance($user));
        $this->assertSame(1, $this->releases());
    }

    public function test_a_provider_refusal_fails_and_releases_immediately(): void
    {
        $user = $this->funded(300_000);
        $this->destination($user, '03000000000'); // the simulated provider refuses accounts ending 0000

        $withdrawal = $this->service()->initiatePayout($this->finance, $this->approved($user));

        $this->assertSame(WithdrawalStatus::Failed, $withdrawal->status);
        $this->assertSame(300_000, $this->balance($user));
    }

    public function test_payouts_are_idempotent_even_after_a_timeout(): void
    {
        $user = $this->funded(300_000);
        $approved = $this->approved($user);

        // The provider times out before recording the payout: the outcome is unknown.
        $this->payouts()->failNext('create');
        $withdrawal = $this->service()->initiatePayout($this->finance, $approved);
        $this->assertSame(WithdrawalStatus::Processing, $withdrawal->status);
        $this->assertSame('timeout', $withdrawal->provider_status);
        $this->assertSame(200_000, $this->balance($user), 'Still held: nothing is concluded from a timeout.');

        // Staff cannot send it again by hand...
        try {
            $this->service()->initiatePayout($this->finance, $withdrawal);
            $this->fail('A processing payout must not be initiated twice.');
        } catch (AdministrativeActionException) {
            $this->addToAssertionCount(1);
        }

        // ...reconciliation re-sends it with the same reference, and it is paid once.
        $this->artisan('withdrawals:reconcile')->assertSuccessful();
        $this->artisan('withdrawals:reconcile')->assertSuccessful();
        $this->assertNotNull($withdrawal->fresh()->provider_reference);
        $this->payouts()->complete($withdrawal->reference, 'paid');
        $this->artisan('withdrawals:reconcile')->assertSuccessful();

        $this->assertSame(WithdrawalStatus::Paid, $withdrawal->fresh()->status);
        $this->assertSame(200_000, $this->balance($user));
        $this->assertSame($withdrawal->fresh()->provider_reference, $this->payouts()->get($withdrawal->reference)['provider_reference']);
    }

    public function test_forged_or_inconsistent_callbacks_are_rejected(): void
    {
        $user = $this->funded(300_000);
        $withdrawal = $this->service()->initiatePayout($this->finance, $this->approved($user));
        $state = $this->payouts()->get($withdrawal->reference);

        $this->post(route('withdrawals.callback', 'fake'), $this->payouts()->notification($state, ['status' => 'failed']))->assertStatus(400); // tampered
        $this->post(route('withdrawals.callback', 'fake'), $this->payouts()->notification($state, ['amount' => '1'], resign: true))->assertStatus(400);
        $this->post(route('withdrawals.callback', 'fake'), ['reference' => $withdrawal->reference, 'status' => 'failed'])->assertStatus(400);
        $this->post(route('withdrawals.callback', 'disabled'), $this->payouts()->notification($state))->assertStatus(400);

        $this->assertSame(WithdrawalStatus::Processing, $withdrawal->fresh()->status);
        $this->assertSame(200_000, $this->balance($user));
    }

    public function test_without_a_payout_provider_nothing_is_paid(): void
    {
        $user = $this->funded(300_000);
        $approved = $this->approved($user);
        config(['competition.withdrawals.gateway' => 'disabled']);

        $this->actingAs($this->finance)->get(route('admin.withdrawals.show', $approved))->assertSee('No payout provider is configured');
        $this->actingAs($this->finance)->post(route('admin.withdrawals.initiate', $approved), ['confirm' => '1'])->assertSessionHas('error');

        $this->assertSame(WithdrawalStatus::Approved, $approved->fresh()->status);
        $this->assertNull($approved->fresh()->paid_at);
    }

    public function test_only_finance_staff_can_act_and_never_on_their_own_withdrawal(): void
    {
        $user = $this->funded(300_000);
        $withdrawal = $this->requested($user);

        foreach ([UserRole::Player, UserRole::Admin, UserRole::ContentManager, UserRole::CompetitionManager] as $role) {
            $staff = User::factory()->role($role)->create();
            $this->actingAs($staff)->get(route('admin.withdrawals.index'))->assertForbidden();
            $this->actingAs($staff)->get(route('admin.withdrawals.show', $withdrawal))->assertForbidden();
            $this->actingAs($staff)->post(route('admin.withdrawals.review', $withdrawal))->assertForbidden();
            $this->actingAs($staff)->post(route('admin.withdrawals.approve', $withdrawal), ['reason' => 'Looks fine', 'confirm' => '1'])->assertForbidden();
            $this->actingAs($staff)->post(route('admin.withdrawals.reject', $withdrawal), ['reason' => 'No reason', 'confirm' => '1'])->assertForbidden();
        }
        $this->assertSame(WithdrawalStatus::Requested, $withdrawal->fresh()->status);

        // Four eyes: a finance manager's own withdrawal must be handled by someone else.
        app(WalletService::class)->credit(app(WalletService::class)->walletFor($this->finance), 300_000, LedgerTransactionType::Deposit, 'own-funds');
        $own = $this->requested($this->finance);
        $this->actingAs($this->finance)->post(route('admin.withdrawals.review', $own))->assertSessionHas('error', 'You cannot review your own withdrawal.');
        $this->actingAs($this->finance)->get(route('admin.withdrawals.show', $own))->assertSee('Another finance team member must review it');

        $this->actingAs($this->finance)->get(route('admin.withdrawals.index'))->assertOk()->assertSee($withdrawal->reference);
    }

    public function test_players_see_only_their_own_withdrawals(): void
    {
        $owner = $this->funded(300_000);
        $withdrawal = $this->requested($owner);
        $other = $this->funded(300_000);

        $this->actingAs($other)->get(route('account.withdrawals.show', $withdrawal->reference))->assertNotFound();
        $this->actingAs($other)->post(route('account.withdrawals.cancel', $withdrawal->reference))->assertNotFound();
        $this->actingAs($other)->get(route('account.withdrawals.index'))->assertDontSee($withdrawal->reference);
        $this->assertSame(WithdrawalStatus::Requested, $withdrawal->fresh()->status);
    }

    public function test_the_ledger_balances_through_the_whole_lifecycle(): void
    {
        config(['competition.withdrawals.max_pending' => 5, 'competition.withdrawals.fee_flat_minor' => 500]);
        $user = $this->funded(1_000_000);
        $this->service()->reject($this->finance, $this->requested($user), 'Test rejection');
        $paid = $this->service()->initiatePayout($this->finance, $this->approved($user));
        $this->post(route('withdrawals.callback', 'fake'), $this->payouts()->complete($paid->reference, 'paid'));

        $this->assertSame(1_000_000 - 100_500, $this->balance($user));
        $this->assertSame(0, (int) DB::table('ledger_entries')->sum('amount_minor'));
        $this->assertSame([], app(WalletReconciler::class)->check());
    }
}
