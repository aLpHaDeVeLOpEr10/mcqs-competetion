<?php

namespace Tests\Feature\Reports;

use App\Enums\LedgerTransactionType;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\UsesFakeGateway;
use Tests\TestCase;

class FinanceReconcileCommandTest extends TestCase
{
    use RefreshDatabase, UsesFakeGateway;

    public function test_a_real_deposit_flow_reconciles(): void
    {
        $this->enableFakeGateway();
        $player = User::factory()->create();
        $payment = $this->startDeposit($player, 50_000);
        $this->fake()->setProviderStatus($payment->reference, 'succeeded');
        $this->deliver($this->fake()->notification($this->providerState($payment)))->assertOk();

        $this->artisan('finance:reconcile')->expectsOutputToContain('Reconciled')->assertSuccessful();
    }

    public function test_money_in_the_ledger_without_its_business_record_is_reported(): void
    {
        $wallets = app(WalletService::class);
        $user = User::factory()->create();
        // A "deposit" credit with no payment behind it (only possible by bypassing the deposit flow).
        $wallets->credit($wallets->walletFor($user), 10_000, LedgerTransactionType::Deposit, 'bypass');

        $exit = Artisan::call('finance:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/Deposits: .*Rs 100\s+<-- investigate/', $output);
        $this->assertStringContainsString('Reconciliation found differences', $output);
        $this->assertSame(10_000, $wallets->getAvailableBalance($user), 'Report only: nothing is changed.');
    }
}
