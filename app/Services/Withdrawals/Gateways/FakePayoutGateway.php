<?php

namespace App\Services\Withdrawals\Gateways;

use App\Contracts\Payments\PayoutGateway;
use App\Contracts\Payments\PayoutRequest;
use App\Contracts\Payments\PayoutResult;
use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\InvalidCallbackException;
use App\Exceptions\Payments\PayoutRefusedException;
use App\Models\Withdrawal;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SIMULATED payout provider for local development and tests: a provider-side
 * record per payout (idempotent by our reference), HMAC-signed notifications
 * and a status query. Accounts ending in 0000 are refused, to exercise that path.
 * PayoutGatewayManager refuses it outside local/testing.
 */
class FakePayoutGateway implements PayoutGateway
{
    protected const FIELDS = ['reference', 'provider_reference', 'amount', 'currency', 'status'];

    public function name(): string
    {
        return 'fake';
    }

    public function supportsStatusQuery(): bool
    {
        return true;
    }

    public function createPayout(PayoutRequest $request): PayoutResult
    {
        $this->maybeFail('create');

        // Idempotent: the same reference never creates a second payout.
        $state = $this->get($request->reference);
        if ($state === null) {
            if (str_ends_with($request->accountNumber, '0000')) {
                throw new PayoutRefusedException('The provider rejected the destination account.');
            }

            $state = [
                'reference' => $request->reference,
                'provider_reference' => 'PO'.Str::upper(Str::random(18)),
                'amount' => $request->amountMinor,
                'currency' => $request->currency,
                'status' => PayoutResult::PROCESSING,
            ];
            $this->put($state);
        }

        return $this->result($state);
    }

    public function handleCallback(Request $request): PayoutResult
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

        return new PayoutResult($fields['status'], $fields['reference'], $fields['provider_reference'], (int) $fields['amount'], $fields['currency'], $fields['status']);
    }

    public function queryPayout(Withdrawal $withdrawal): PayoutResult
    {
        $this->maybeFail('query');
        $state = $this->get($withdrawal->reference);

        return $state ? $this->result($state) : new PayoutResult(PayoutResult::NOT_FOUND, $withdrawal->reference, providerStatus: 'not_found');
    }

    // ---- simulation controls ---------------------------------------------------------

    /**
     * The provider finishes the payout; returns the signed notification it would send.
     *
     * @return array<string, string>
     */
    public function complete(string $reference, string $status): array
    {
        $state = $this->get($reference) ?? throw new GatewayException('Unknown simulated payout.');
        $state['status'] = $status;
        $this->put($state);

        return $this->notification($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, string>  $overrides  applied after signing (tampering) unless $resign
     * @return array<string, string>
     */
    public function notification(array $state, array $overrides = [], bool $resign = false): array
    {
        $fields = array_map('strval', array_intersect_key($state, array_flip(self::FIELDS)));
        $signature = $this->sign($fields);
        $fields = array_merge($fields, $overrides);

        return $fields + ['signature' => $resign ? $this->sign($fields) : $signature];
    }

    public function failNext(string $operation, string $mode = 'timeout'): void
    {
        $this->store()->put("fake-payout:fail:{$operation}", $mode, 3600);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $reference): ?array
    {
        return $this->store()->get("fake-payout:{$reference}");
    }

    // ---- internals -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $state
     */
    protected function result(array $state): PayoutResult
    {
        return new PayoutResult($state['status'], $state['reference'], $state['provider_reference'], $state['amount'], $state['currency'], $state['status']);
    }

    /**
     * @param  array<string, string>  $fields
     */
    protected function sign(array $fields): string
    {
        $fields = array_intersect_key($fields, array_flip(self::FIELDS));
        ksort($fields);

        return hash_hmac('sha256', http_build_query($fields), (string) config('competition.withdrawals.fake.secret'));
    }

    protected function maybeFail(string $operation): void
    {
        $mode = $this->store()->pull("fake-payout:fail:{$operation}");
        if ($mode !== null) {
            throw $mode === 'timeout' ? GatewayException::timeout($operation) : GatewayException::unavailable();
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function put(array $state): void
    {
        $this->store()->put("fake-payout:{$state['reference']}", $state, 86400);
    }

    protected function store(): Repository
    {
        return Cache::store(config('competition.withdrawals.fake.cache_store'));
    }
}
