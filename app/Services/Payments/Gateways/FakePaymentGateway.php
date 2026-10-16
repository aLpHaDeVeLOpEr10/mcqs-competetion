<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\Payments\CheckoutSession;
use App\Contracts\Payments\GatewayResult;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentRequest;
use App\Enums\GatewayStatus;
use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\InvalidCallbackException;
use App\Models\PaymentTransaction;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A SIMULATED provider for local development and automated tests. It models
 * the parts that matter for security — a provider-side record of each
 * payment, HMAC-SHA256 signed notifications with a merchant id, and a
 * server-to-server status query — without any real money or network.
 *
 * PaymentGatewayManager refuses to resolve it outside local/testing, unless
 * FAKE_GATEWAY_ENABLED=true, or without FAKE_GATEWAY_SECRET.
 */
class FakePaymentGateway implements PaymentGateway
{
    protected const FIELDS = ['merchant_id', 'reference', 'provider_reference', 'amount', 'currency', 'status', 'event_id'];

    public function name(): string
    {
        return 'fake';
    }

    public function supportsStatusQuery(): bool
    {
        return true;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function createPayment(PaymentRequest $request): CheckoutSession
    {
        $this->maybeFail('create');

        $providerReference = 'FK'.Str::upper(Str::random(18));
        $this->put([
            'reference' => $request->reference,
            'provider_reference' => $providerReference,
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
            'status' => 'pending',
            'return_url' => $request->returnUrl,
        ]);

        return CheckoutSession::redirect(route('fake-gateway.show', $providerReference), $providerReference);
    }

    public function handleCallback(Request $request): GatewayResult
    {
        $fields = [];
        foreach (self::FIELDS as $field) {
            $value = $request->post($field);
            if (! is_string($value) || $value === '') {
                throw new InvalidCallbackException('malformed');
            }
            $fields[$field] = $value;
        }

        if (! hash_equals($this->sign($fields), (string) $request->post('signature'))) {
            throw new InvalidCallbackException('bad_signature');
        }

        if (! hash_equals((string) config('competition.payments.fake.merchant_id'), $fields['merchant_id'])) {
            throw new InvalidCallbackException('wrong_merchant', signatureValid: true);
        }

        if (! ctype_digit($fields['amount'])) {
            throw new InvalidCallbackException('malformed', signatureValid: true);
        }

        return new GatewayResult(
            status: $this->status($fields['status']),
            reference: $fields['reference'],
            providerReference: $fields['provider_reference'],
            amountMinor: (int) $fields['amount'],
            currency: $fields['currency'],
            providerStatus: $fields['status'],
            payload: $fields,
        );
    }

    public function queryPaymentStatus(PaymentTransaction $payment): GatewayResult
    {
        $this->maybeFail('query');

        $state = $this->get($payment->reference);
        if ($state === null) {
            return new GatewayResult(GatewayStatus::NotFound, $payment->reference, providerStatus: 'not_found');
        }

        return new GatewayResult(
            status: $this->status($state['status']),
            reference: $state['reference'],
            providerReference: $state['provider_reference'],
            amountMinor: $state['amount'],
            currency: $state['currency'],
            providerStatus: $state['status'],
            payload: ['source' => 'status_query', 'status' => $state['status']],
        );
    }

    public function refundPayment(PaymentTransaction $payment, int $amountMinor, string $reason): GatewayResult
    {
        throw GatewayException::unavailable('The simulated provider does not support refunds.');
    }

    // ---- Simulation controls (local checkout page and tests only) ------------------

    /**
     * The simulated customer approves or declines on the provider's page. Returns
     * the signed notification the provider would send to our callback URL.
     *
     * @return array<string, string>
     */
    public function complete(string $providerReference, string $outcome): array
    {
        $state = $this->byProviderReference($providerReference) ?? throw new GatewayException('Unknown simulated payment.');

        if ($state['status'] === 'pending') {
            $state['status'] = $outcome === 'succeeded' ? 'succeeded' : 'failed';
            $this->put($state);
        }

        return $this->notification($state);
    }

    /**
     * The signed notification for a provider-side state. $overrides change fields
     * afterwards: with $resign = true they are signed (a provider sending odd data),
     * with $resign = false the original signature is kept (tampering in transit).
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    public function notification(array $state, array $overrides = [], bool $resign = true): array
    {
        $fields = [
            'merchant_id' => (string) config('competition.payments.fake.merchant_id'),
            'reference' => (string) $state['reference'],
            'provider_reference' => (string) $state['provider_reference'],
            'amount' => (string) $state['amount'],
            'currency' => (string) $state['currency'],
            'status' => (string) $state['status'],
            'event_id' => (string) Str::uuid(),
        ];
        $signature = $this->sign($fields);

        $fields = array_merge($fields, $overrides);
        if ($resign) {
            $signature = $this->sign(array_intersect_key($fields, array_flip(self::FIELDS)));
        }

        return $fields + ['signature' => $signature];
    }

    /**
     * Change the provider-side status directly (e.g. the provider expires it).
     */
    public function setProviderStatus(string $reference, string $status): void
    {
        $state = $this->get($reference) ?? throw new GatewayException('Unknown simulated payment.');
        $state['status'] = $status;
        $this->put($state);
    }

    /**
     * Make the next call of an operation ("create" or "query") time out or fail.
     */
    public function failNext(string $operation, string $mode = 'timeout'): void
    {
        $this->store()->put("fake-gateway:fail:{$operation}", $mode, 3600);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function byProviderReference(string $providerReference): ?array
    {
        $reference = $this->store()->get("fake-gateway:provider:{$providerReference}");

        return $reference ? $this->get($reference) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $reference): ?array
    {
        return $this->store()->get("fake-gateway:payment:{$reference}");
    }

    // ---- Internals --------------------------------------------------------------------

    /**
     * @param  array<string, string>  $fields
     */
    protected function sign(array $fields): string
    {
        ksort($fields);

        return hash_hmac('sha256', http_build_query($fields), (string) config('competition.payments.fake.secret'));
    }

    protected function status(string $status): GatewayStatus
    {
        return GatewayStatus::tryFrom($status) ?? throw new InvalidCallbackException('unknown_status', signatureValid: true);
    }

    protected function maybeFail(string $operation): void
    {
        $mode = $this->store()->pull("fake-gateway:fail:{$operation}");

        if ($mode === 'timeout') {
            throw GatewayException::timeout($operation);
        }
        if ($mode !== null) {
            throw GatewayException::unavailable('The simulated provider returned an error.');
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function put(array $state): void
    {
        $this->store()->put("fake-gateway:payment:{$state['reference']}", $state, 86400);
        $this->store()->put("fake-gateway:provider:{$state['provider_reference']}", $state['reference'], 86400);
    }

    protected function store(): Repository
    {
        return Cache::store(config('competition.payments.fake.cache_store'));
    }
}
