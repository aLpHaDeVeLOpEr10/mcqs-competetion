<?php

namespace Tests\Feature\Wallet;

use App\Enums\LedgerTransactionType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletPagesTest extends TestCase
{
    use RefreshDatabase;

    protected WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallets = app(WalletService::class);
    }

    protected function playerWithHistory(): array
    {
        $user = User::factory()->create();
        $wallet = $this->wallets->walletFor($user);
        $deposit = $this->wallets->credit($wallet, 25_000, LedgerTransactionType::Deposit, 'secret-internal-key-'.$user->id, 'Opening balance', metadata: ['room_id' => 77]);
        $fee = $this->wallets->debit($wallet, 5_050, LedgerTransactionType::EntryFee, 'fee-key-'.$user->id, 'Entry fee: Weekend Cup');
        $this->wallets->refund($fee, 'Room cancelled');

        return [$user, $wallet, $deposit, $fee];
    }

    public function test_the_wallet_shows_balance_and_history_without_internal_data(): void
    {
        [$user, , $deposit, $fee] = $this->playerWithHistory();

        $this->actingAs($user)->get(route('account.wallet'))
            ->assertOk()
            ->assertSee('Rs 250')
            ->assertSee('Entry fee refund')
            ->assertSee($fee->reference)
            ->assertSee('Reversed')
            ->assertSee('Completed')
            ->assertSee('Deposits and withdrawals are not available yet')
            ->assertDontSee('secret-internal-key')
            ->assertDontSee('fee-key-');

        $this->actingAs($user)->get(route('account.transactions'))
            ->assertOk()
            ->assertSee($deposit->reference)
            ->assertSee('Entry fee')
            ->assertSee('-Rs 50.50')
            ->assertDontSee('secret-internal-key');
    }

    public function test_deposit_and_withdrawal_controls_do_not_pretend_to_work(): void
    {
        [$user] = $this->playerWithHistory();

        $html = $this->actingAs($user)->get(route('account.wallet'))->getContent();

        // Each control is a disabled <button> (not a link) whose own content is the label.
        $this->assertMatchesRegularExpression('#<button[^>]*disabled[^>]*>(?:(?!</button>).)*Add money#s', $html);
        $this->assertMatchesRegularExpression('#<button[^>]*disabled[^>]*>(?:(?!</button>).)*Withdraw#s', $html);
        $this->assertFalse(app('router')->has('account.wallet.deposit'));
        $this->assertFalse(app('router')->has('account.wallet.withdraw'));
    }

    public function test_players_can_open_only_their_own_transactions(): void
    {
        [$owner, , $deposit] = $this->playerWithHistory();
        [$other] = $this->playerWithHistory();

        $this->actingAs($owner)->get(route('account.transactions.show', $deposit->reference))
            ->assertOk()
            ->assertSee($deposit->reference)
            ->assertSee('+Rs 250')
            ->assertSee('Opening balance')
            ->assertDontSee('secret-internal-key')
            ->assertDontSee('room_id');

        $this->actingAs($other)->get(route('account.transactions.show', $deposit->reference))->assertForbidden();
        $this->actingAs($other)->get('/account/wallet/transactions/'.Str::ulid())->assertNotFound();
        $this->actingAs($other)->get('/account/wallet/transactions/'.$deposit->id)->assertNotFound();

        $this->signOut();
        $this->get(route('account.transactions.show', $deposit->reference))->assertRedirect(route('login'));
        $this->get(route('account.wallet'))->assertRedirect(route('login'));
    }

    public function test_wallet_queries_never_take_a_wallet_id_from_the_request(): void
    {
        [$owner] = $this->playerWithHistory();
        [$other, $otherWallet, $otherDeposit] = $this->playerWithHistory();

        $this->actingAs($owner)->get(route('account.wallet', ['wallet_id' => $otherWallet->id, 'user_id' => $other->id]))
            ->assertOk()->assertDontSee($otherDeposit->reference);
        $this->actingAs($owner)->get(route('account.transactions', ['wallet' => $otherWallet->id]))
            ->assertOk()->assertDontSee($otherDeposit->reference);
    }

    public function test_finance_staff_see_wallets_and_ledger_but_players_cannot(): void
    {
        [$player, $wallet, $deposit] = $this->playerWithHistory();
        $finance = User::factory()->role(UserRole::FinanceManager)->create();

        $this->actingAs($finance)->get(route('admin.wallets.index'))->assertOk()->assertSee($player->display_name)->assertDontSee('Mismatch');
        $this->actingAs($finance)->get(route('admin.wallets.show', $wallet))->assertOk()->assertSee($deposit->reference)->assertSee('Matches the ledger');
        $this->actingAs($finance)->get(route('admin.ledger.show', $deposit))->assertOk()->assertSee($deposit->reference)->assertSee('room_id');

        foreach ([$player, User::factory()->role(UserRole::ContentManager)->create(), User::factory()->role(UserRole::CompetitionManager)->create()] as $user) {
            $this->actingAs($user)->get(route('admin.wallets.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.wallets.show', $wallet))->assertForbidden();
        }
    }

    public function test_adjustments_are_disabled_by_default(): void
    {
        [, $wallet] = $this->playerWithHistory();
        $finance = User::factory()->role(UserRole::FinanceManager)->create();
        $count = LedgerTransaction::query()->count();

        $this->actingAs($finance)->get(route('admin.wallets.show', $wallet))->assertSee('Wallet adjustments are disabled');
        $this->actingAs($finance)->post(route('admin.wallets.adjust', $wallet), $this->adjustment())
            ->assertRedirect()->assertSessionHas('error', 'Wallet adjustments are disabled on this platform.');

        $this->assertSame($count, LedgerTransaction::query()->count());
    }

    public function test_an_authorized_adjustment_is_posted_once_and_audited(): void
    {
        config(['competition.wallet.adjustments_enabled' => true]);
        [$player, $wallet] = $this->playerWithHistory();
        $finance = User::factory()->role(UserRole::FinanceManager)->create();
        $form = $this->adjustment(['amount' => '1,000.25']);

        $this->actingAs($finance)->post(route('admin.wallets.adjust', $wallet), $form)
            ->assertRedirect(route('admin.wallets.show', $wallet))->assertSessionHas('success');
        // Double submit of the same form: nothing more is posted.
        $this->actingAs($finance)->post(route('admin.wallets.adjust', $wallet), $form)->assertSessionHas('success');

        $this->assertSame(25_000 + 100_025, $this->wallets->getAvailableBalance($wallet));
        $adjustment = LedgerTransaction::query()->where('type', LedgerTransactionType::Adjustment)->sole();
        $this->assertSame($finance->id, $adjustment->created_by);
        $this->assertStringContainsString('Goodwill credit after outage', $adjustment->description);

        $log = AuditLog::query()->where('action', 'wallet.adjusted')->sole();
        $this->assertSame($finance->id, $log->actor_id);
        $this->assertSame(['balance_minor' => 25_000], $log->old_values);
        $this->assertSame(['balance_minor' => 125_025], $log->new_values);
        $this->assertSame($adjustment->reference, $log->metadata['ledger_reference']);
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_an_adjustment_cannot_overdraw_a_wallet(): void
    {
        config(['competition.wallet.adjustments_enabled' => true]);
        [, $wallet] = $this->playerWithHistory();
        $finance = User::factory()->role(UserRole::FinanceManager)->create();

        $this->actingAs($finance)->post(route('admin.wallets.adjust', $wallet), $this->adjustment(['direction' => 'debit', 'amount' => '250.01']))
            ->assertSessionHas('error', 'The wallet balance is too low for this payment.');

        $this->assertSame(25_000, $this->wallets->getAvailableBalance($wallet));
        $this->assertSame(0, AuditLog::query()->where('action', 'wallet.adjusted')->count());
    }

    public function test_adjustments_require_permission_reason_and_a_player_wallet(): void
    {
        config(['competition.wallet.adjustments_enabled' => true]);
        [, $wallet] = $this->playerWithHistory();
        $finance = User::factory()->role(UserRole::FinanceManager)->create();
        $count = LedgerTransaction::query()->count();

        // Admins and players cannot adjust; nor can finance staff adjust their own wallet or a platform wallet.
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())->post(route('admin.wallets.adjust', $wallet), $this->adjustment())->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('admin.wallets.adjust', $wallet), $this->adjustment())->assertForbidden();
        $this->actingAs($finance)->post(route('admin.wallets.adjust', $this->wallets->walletFor($finance)), $this->adjustment())->assertForbidden();
        $system = Wallet::query()->where('system_code', Wallet::SYSTEM_GATEWAY_CLEARING)->firstOrFail();
        $this->actingAs($finance)->post(route('admin.wallets.adjust', $system), $this->adjustment())->assertForbidden();

        $this->actingAs($finance)->post(route('admin.wallets.adjust', $wallet), $this->adjustment(['reason' => 'short', 'amount' => 'abc', 'confirm' => null, 'token' => 'x']))
            ->assertSessionHasErrorsIn('adjust', ['reason', 'amount', 'confirm', 'token']);
        $this->actingAs($finance)->post(route('admin.wallets.adjust', $wallet), $this->adjustment(['amount' => '0']))
            ->assertSessionHasErrorsIn('adjust', ['amount']);
        $this->actingAs($finance)->post(route('admin.wallets.adjust', $wallet), $this->adjustment(['amount' => '50000.01']))
            ->assertSessionHas('error');

        $this->assertSame($count, LedgerTransaction::query()->count());
    }

    protected function adjustment(array $overrides = []): array
    {
        return array_merge([
            'direction' => 'credit',
            'amount' => '100',
            'reason' => 'Goodwill credit after outage',
            'token' => (string) Str::uuid(),
            'confirm' => '1',
        ], $overrides);
    }
}
