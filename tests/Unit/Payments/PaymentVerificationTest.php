<?php

namespace Tests\Unit\Payments;

use App\Enums\GatewayStatus;
use App\Exceptions\Payments\InvalidCallbackException;
use App\Services\Payments\Gateways\FakePaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Callback verification of the simulated provider (the contract a real driver must also meet):
 * signature over every field, merchant check, well-formed amount. No database involved.
 */
class PaymentVerificationTest extends TestCase
{
    protected FakePaymentGateway $gateway;

    protected array $state = ['reference' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'provider_reference' => 'FK123', 'amount' => 50_000, 'currency' => 'PKR', 'status' => 'succeeded'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'competition.payments.gateway' => 'fake',
            'competition.payments.fake.enabled' => true,
            'competition.payments.fake.secret' => 'unit-test-secret',
            'competition.payments.fake.merchant_id' => 'FAKE-MERCHANT',
            'competition.payments.fake.cache_store' => 'array',
        ]);
        app(PaymentGatewayManager::class)->forgetResolved();
        $this->gateway = app(PaymentGatewayManager::class)->driver('fake');
    }

    protected function verify(array $fields): mixed
    {
        return $this->gateway->handleCallback(Request::create('/payments/callback/fake', 'POST', $fields));
    }

    protected function reason(array $fields): string
    {
        try {
            $this->verify($fields);
        } catch (InvalidCallbackException $e) {
            return $e->reason.($e->signatureValid ? ' (signed)' : '');
        }

        return 'accepted';
    }

    public function test_a_correctly_signed_notification_is_parsed_exactly(): void
    {
        $result = $this->verify($this->gateway->notification($this->state));

        $this->assertSame(GatewayStatus::Succeeded, $result->status);
        $this->assertSame([$this->state['reference'], 'FK123', 50_000, 'PKR'], [$result->reference, $result->providerReference, $result->amountMinor, $result->currency]);
    }

    public function test_tampering_with_any_signed_field_is_rejected(): void
    {
        foreach (['amount' => '5000000', 'currency' => 'USD', 'status' => 'succeeded ', 'reference' => 'OTHER', 'provider_reference' => 'FK999'] as $field => $value) {
            $this->assertSame('bad_signature', $this->reason($this->gateway->notification($this->state, [$field => $value], resign: false)), $field);
        }
    }

    public function test_a_wrong_secret_or_missing_signature_is_rejected(): void
    {
        $fields = $this->gateway->notification($this->state);
        config(['competition.payments.fake.secret' => 'another-secret']);
        $this->assertSame('bad_signature', $this->reason($fields));

        unset($fields['signature']);
        $this->assertSame('bad_signature', $this->reason($fields));
    }

    public function test_signed_but_invalid_content_is_still_rejected(): void
    {
        $this->assertSame('wrong_merchant (signed)', $this->reason($this->gateway->notification($this->state, ['merchant_id' => 'SOMEONE-ELSE'])));
        $this->assertSame('malformed (signed)', $this->reason($this->gateway->notification($this->state, ['amount' => '12.50'])));
        $this->assertSame('malformed (signed)', $this->reason($this->gateway->notification($this->state, ['amount' => '-100'])));
        $this->assertSame('unknown_status (signed)', $this->reason($this->gateway->notification($this->state, ['status' => 'paid_maybe'])));
    }

    public function test_missing_fields_are_malformed(): void
    {
        $fields = $this->gateway->notification($this->state);
        unset($fields['currency']);

        $this->assertSame('malformed', $this->reason($fields));
        $this->assertSame('malformed', $this->reason([]));
    }
}
