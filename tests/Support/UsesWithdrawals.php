<?php

namespace Tests\Support;

use App\Enums\LedgerTransactionType;
use App\Enums\PayoutMethod;
use App\Models\PayoutDestination;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Services\Withdrawals\Gateways\FakePayoutGateway;
use App\Services\Withdrawals\PayoutDestinationService;
use App\Services\Withdrawals\PayoutGatewayManager;

/**
 * Withdrawals enabled with the simulated payout provider (local/testing only).
 */
trait UsesWithdrawals
{
    protected const PAYOUT_SECRET = 'test-only-fake-payout-secret-0123456789';

    protected const MOBILE = '03001234567';

    protected function enableWithdrawals(array $overrides = []): void
    {
        config(array_merge([
            'competition.withdrawals.enabled' => true,
            'competition.withdrawals.gateway' => 'fake',
            'competition.withdrawals.fake.enabled' => true,
            'competition.withdrawals.fake.secret' => self::PAYOUT_SECRET,
            'competition.withdrawals.fake.cache_store' => 'array',
            'competition.withdrawals.min_minor' => 50_000,
            'competition.withdrawals.max_minor' => 2_500_000,
            'competition.withdrawals.daily_max_minor' => 5_000_000,
            'competition.withdrawals.max_pending' => 1,
            'competition.withdrawals.fee_flat_minor' => 0,
            'competition.withdrawals.fee_bp' => 0,
            'competition.withdrawals.destination_cooldown_hours' => 24,
        ], $overrides));
    }

    protected function payouts(): FakePayoutGateway
    {
        return app(PayoutGatewayManager::class)->driver('fake');
    }

    protected function funded(int $balance, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->walletFor($user), $balance, LedgerTransactionType::Deposit, 'test-funds:'.$user->id);

        return $user;
    }

    /**
     * A saved destination that is already past its cooling-off period.
     */
    protected function destination(User $user, string $number = self::MOBILE): PayoutDestination
    {
        $destination = app(PayoutDestinationService::class)->add($user, PayoutMethod::MobileWallet, 'JAZZCASH', 'Test Account Holder', $number);
        $destination->forceFill(['usable_after' => now()->subMinute()])->save();

        return $destination;
    }

    protected function balance(User $user): int
    {
        return app(WalletService::class)->getAvailableBalance($user);
    }
}
