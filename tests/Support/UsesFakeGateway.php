<?php

namespace Tests\Support;

use App\Enums\PaymentStatus;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\DepositService;
use App\Services\Payments\Gateways\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Enables the simulated provider for a test, with deposits switched on.
 */
trait UsesFakeGateway
{
    protected const FAKE_SECRET = 'test-only-fake-gateway-secret-0123456789abcdef';

    protected function enableFakeGateway(string $cacheStore = 'array'): void
    {
        config([
            'competition.payments.gateway' => 'fake',
            'competition.payments.deposits_enabled' => true,
            'competition.payments.fake.enabled' => true,
            'competition.payments.fake.secret' => self::FAKE_SECRET,
            'competition.payments.fake.merchant_id' => 'FAKE-MERCHANT',
            'competition.payments.fake.cache_store' => $cacheStore,
        ]);
        app(PaymentGatewayManager::class)->forgetResolved();
    }

    protected function fake(): FakePaymentGateway
    {
        return app(PaymentGatewayManager::class)->driver('fake');
    }

    protected function startDeposit(User $user, int $amountMinor = 50_000): PaymentTransaction
    {
        $payment = app(DepositService::class)->initiate($user, $amountMinor, (string) Str::uuid());
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        return $payment;
    }

    /**
     * The provider-side state of a payment.
     *
     * @return array<string, mixed>
     */
    protected function providerState(PaymentTransaction $payment): array
    {
        return $this->fake()->get($payment->reference);
    }

    /**
     * Deliver a provider notification to the real callback endpoint.
     *
     * @param  array<string, string>  $fields
     */
    protected function deliver(array $fields, string $gateway = 'fake'): TestResponse
    {
        return $this->post(route('payments.callback', $gateway), $fields);
    }
}
