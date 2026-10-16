<?php

namespace App\Services\Payments;

use App\Contracts\Payments\GatewayResult;
use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentRequest;
use App\Enums\GatewayStatus;
use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Exceptions\Payments\DepositException;
use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\InvalidCallbackException;
use App\Models\PaymentCallback;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\DepositConfirmed;
use App\Notifications\DepositFailed;
use App\Services\Notifications\Notifier;
use App\Services\Security\RiskMonitor;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use App\Support\SensitiveData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

/**
 * Deposit lifecycle, independent of any provider:
 *
 *  initiate  validate amount and limits → payment "created" with our reference →
 *            provider createPayment() → "pending" with checkout instructions
 *  callback  driver authenticates the notification (signature, merchant) →
 *            every field is checked against the stored payment → the outcome is
 *            confirmed with a server-to-server status query (when the provider
 *            offers one) → settle()
 *  settle    under a row lock on the payment: a verified success credits the
 *            wallet exactly once (payment lock + final-status check + unique
 *            ledger idempotency key + unique ledger_transaction_id)
 *
 * Browser redirects, query strings, client-sent amounts or statuses are never
 * used to decide anything. Provider calls are never made inside a database
 * transaction.
 */
class DepositService
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected WalletService $wallets,
    ) {}

    /**
     * @throws DepositException
     */
    public function initiate(User $user, int $amountMinor, string $token): PaymentTransaction
    {
        if (! $this->gateways->depositsAvailable()) {
            throw new DepositException('Deposits are not available right now.');
        }

        if (! $user->canParticipate()) {
            throw new DepositException('Your account cannot make payments right now.');
        }

        $limits = config('competition.payments.deposit');
        if ($amountMinor < $limits['min_minor'] || $amountMinor > $limits['max_minor']) {
            throw new DepositException('Enter an amount between '.Money::format($limits['min_minor']).' and '.Money::format($limits['max_minor']).'.');
        }

        $gateway = $this->gateways->gateway();
        $wallet = $this->wallets->walletFor($user);
        $key = "deposit:{$user->id}:{$token}";

        $payment = DB::transaction(function () use ($user, $wallet, $amountMinor, $key, $gateway, $limits) {
            // Serializes this player's deposit requests so the limits cannot be raced.
            Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();

            if ($existing = PaymentTransaction::query()->where('idempotency_key', $key)->where('user_id', $user->id)->first()) {
                return $existing;
            }

            $mine = PaymentTransaction::query()->where('user_id', $user->id)->where('type', PaymentType::Deposit);

            if ((clone $mine)->whereIn('status', PaymentStatus::open())->where('expires_at', '>', now())->count() >= $limits['max_open']) {
                throw new DepositException('You have too many unfinished deposits. Complete or wait for them to expire first.');
            }

            $today = (int) (clone $mine)->where('created_at', '>=', now()->subDay())
                ->whereIn('status', [...PaymentStatus::open(), PaymentStatus::Succeeded])->sum('amount_minor');
            if ($today + $amountMinor > $limits['daily_max_minor']) {
                throw new DepositException('This would exceed the deposit limit of '.Money::format($limits['daily_max_minor']).' per 24 hours.');
            }

            return tap((new PaymentTransaction)->forceFill([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'type' => PaymentType::Deposit,
                'status' => PaymentStatus::Created,
                'gateway' => $gateway->name(),
                'amount_minor' => $amountMinor,
                'currency' => $wallet->currency,
                'idempotency_key' => $key,
                'expires_at' => now()->addMinutes((int) $limits['expires_minutes']),
            ]))->save();
        });

        // A double-submitted form returns the first request; the provider is called once.
        return $payment->wasRecentlyCreated ? $this->startCheckout($payment, $gateway) : $payment;
    }

    protected function startCheckout(PaymentTransaction $payment, PaymentGateway $gateway): PaymentTransaction
    {
        try {
            $checkout = $gateway->createPayment(new PaymentRequest(
                reference: $payment->reference,
                amountMinor: $payment->amount_minor,
                currency: $payment->currency,
                description: 'Wallet deposit '.$payment->reference,
                returnUrl: route('account.deposits.show', $payment->reference),
                callbackUrl: route('payments.callback', $gateway->name()),
                expiresAt: $payment->expires_at,
            ));
        } catch (GatewayException $e) {
            // The player never reached the provider, so they cannot have paid. The payment
            // stays "created" and the reconciler expires it after confirming with the provider.
            $payment->forceFill([
                'failure_code' => $e->timeout ? 'provider_timeout' : 'provider_unavailable',
                'failure_message' => 'The payment provider could not be reached.',
            ])->save();
            Log::warning('Deposit could not be created at the payment provider.', ['payment' => $payment->reference, 'gateway' => $gateway->name(), 'timeout' => $e->timeout]);

            throw new DepositException('The payment provider could not be reached. No money was taken; please try again in a few minutes.');
        }

        DB::transaction(function () use ($payment, $checkout) {
            $locked = PaymentTransaction::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === PaymentStatus::Created) {
                $locked->forceFill([
                    'status' => PaymentStatus::Pending,
                    'gateway_reference' => $checkout->providerReference,
                    'checkout' => $checkout->toArray(),
                    'failure_code' => null,
                    'failure_message' => null,
                ])->save();
            }
        });

        return $payment->refresh();
    }

    /**
     * Process a provider notification. Returns a PaymentCallback outcome; every
     * notification is logged, including rejected ones.
     */
    public function handleCallback(string $gatewayName, Request $request): string
    {
        try {
            $gateway = $this->gateways->driver($gatewayName);
            $result = $gateway->handleCallback($request);
        } catch (LogicException) {
            return $this->log($request, $gatewayName, null, false, PaymentCallback::REJECTED, 'unknown_gateway');
        } catch (InvalidCallbackException $e) {
            Log::warning('Rejected payment callback.', ['gateway' => $gatewayName, 'reason' => $e->reason, 'ip' => $request->ip()]);

            return $this->log($request, $gatewayName, null, $e->signatureValid, PaymentCallback::REJECTED, $e->reason);
        }

        $payment = PaymentTransaction::query()
            ->where('reference', $result->reference)->where('gateway', $gateway->name())->where('type', PaymentType::Deposit)
            ->first();

        if ($payment === null) {
            return $this->log($request, $gatewayName, null, true, PaymentCallback::REJECTED, 'unknown_payment');
        }

        if ($mismatch = $this->mismatch($payment, $result)) {
            Log::warning('Inconsistent payment callback rejected.', ['payment' => $payment->reference, 'reason' => $mismatch]);

            return $this->log($request, $gatewayName, $payment, true, PaymentCallback::REJECTED, $mismatch);
        }

        // Independent verification: ask the provider directly instead of trusting the notification.
        if ($gateway->supportsStatusQuery()) {
            try {
                $verified = $gateway->queryPaymentStatus($payment);
            } catch (GatewayException) {
                return $this->log($request, $gatewayName, $payment, true, PaymentCallback::PENDING, 'verification_unavailable');
            }

            if ($mismatch = $this->mismatch($payment, $verified)) {
                Log::warning('Payment status query disagrees with the stored payment.', ['payment' => $payment->reference, 'reason' => $mismatch]);

                return $this->log($request, $gatewayName, $payment, true, PaymentCallback::REJECTED, 'verification_'.$mismatch);
            }
        } else {
            $verified = $result;
        }

        $outcome = $this->settle($payment, $verified);

        return $this->log($request, $gatewayName, $payment, true, $outcome, $outcome === PaymentCallback::DUPLICATE ? 'already_final' : $verified->status->value);
    }

    /**
     * Re-check an unfinished payment with the provider (status page, reconciler).
     */
    public function refresh(PaymentTransaction $payment): string
    {
        if ($payment->status->isFinal()) {
            return PaymentCallback::DUPLICATE;
        }

        try {
            $gateway = $this->gateways->driver($payment->gateway);
        } catch (LogicException) {
            return PaymentCallback::PENDING;
        }

        if (! $gateway->supportsStatusQuery()) {
            // Without a status query only an authenticated callback can confirm success;
            // an unanswered payment simply expires (a later valid callback still settles it).
            return $payment->expires_at?->isPast() && $payment->status->isOpen()
                ? $this->settle($payment, new GatewayResult(GatewayStatus::Expired, $payment->reference, providerStatus: 'expired_locally'))
                : PaymentCallback::PENDING;
        }

        try {
            $result = $gateway->queryPaymentStatus($payment);
        } catch (GatewayException) {
            return PaymentCallback::PENDING;
        }

        if ($mismatch = $this->mismatch($payment, $result)) {
            Log::warning('Payment status query disagrees with the stored payment.', ['payment' => $payment->reference, 'reason' => $mismatch]);

            return PaymentCallback::REJECTED;
        }

        return $this->settle($payment, $result);
    }

    /**
     * Apply a VERIFIED provider outcome. Idempotent and safe under concurrency.
     */
    public function settle(PaymentTransaction $payment, GatewayResult $result): string
    {
        return DB::transaction(function () use ($payment, $result) {
            // First statement: lock the payment row (see the REPEATABLE READ note in WALLET.md).
            $payment = PaymentTransaction::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status->isFinal()) {
                return PaymentCallback::DUPLICATE;
            }

            $now = now();
            $common = [
                'provider_status' => $result->providerStatus ? Str::limit($result->providerStatus, 60, '') : $payment->provider_status,
                'gateway_reference' => $payment->gateway_reference ?? $result->providerReference,
            ];

            switch ($result->status) {
                case GatewayStatus::Succeeded:
                    // The amount credited is OUR stored amount, already checked against the provider's.
                    $ledger = $this->wallets->credit(
                        $payment->wallet()->firstOrFail(), $payment->amount_minor, LedgerTransactionType::Deposit,
                        'deposit:'.$payment->reference, 'Deposit '.$payment->reference, $payment,
                        ['gateway' => $payment->gateway, 'provider_reference' => $common['gateway_reference']],
                    );
                    $payment->forceFill($common + [
                        'status' => PaymentStatus::Succeeded, 'ledger_transaction_id' => $ledger->id,
                        'verified_at' => $now, 'completed_at' => $now, 'failure_code' => null, 'failure_message' => null,
                    ])->save();
                    app(Notifier::class)->afterCommit(fn () => $payment->user, new DepositConfirmed($payment));

                    return PaymentCallback::APPLIED;

                case GatewayStatus::Failed:
                    $payment->forceFill($common + [
                        'status' => PaymentStatus::Failed, 'verified_at' => $now, 'completed_at' => $now,
                        'failure_code' => Str::limit((string) ($result->providerStatus ?: 'declined'), 60, ''),
                        'failure_message' => 'The payment was declined or cancelled at the provider.',
                    ])->save();
                    app(Notifier::class)->afterCommit(fn () => $payment->user, new DepositFailed($payment));

                    return PaymentCallback::APPLIED;

                case GatewayStatus::Expired:
                    $payment->forceFill($common + ['status' => PaymentStatus::Expired, 'completed_at' => $now, 'failure_code' => 'expired'])->save();

                    return PaymentCallback::APPLIED;

                case GatewayStatus::NotFound:
                    if ($payment->status->isOpen() && $payment->expires_at?->isPast()) {
                        $payment->forceFill(['status' => PaymentStatus::Expired, 'completed_at' => $now, 'failure_code' => 'not_found_at_provider'])->save();

                        return PaymentCallback::APPLIED;
                    }

                    return PaymentCallback::PENDING;

                default:
                    // Still unpaid at the provider after our deadline: expire it (a later verified
                    // success still settles it). "Processing" means money is moving, so it waits.
                    if ($result->status === GatewayStatus::Pending && $payment->status->isOpen() && $payment->expires_at?->isPast()) {
                        $payment->forceFill($common + ['status' => PaymentStatus::Expired, 'completed_at' => $now, 'failure_code' => 'expired_unpaid'])->save();

                        return PaymentCallback::APPLIED;
                    }

                    if ($payment->status->isOpen()) {
                        $payment->forceFill($common + ['status' => $result->status === GatewayStatus::Processing ? PaymentStatus::Processing : PaymentStatus::Pending])->save();
                    }

                    return PaymentCallback::PENDING;
            }
        });
    }

    /**
     * Why a provider statement cannot belong to this payment, or null when it is consistent.
     */
    protected function mismatch(PaymentTransaction $payment, GatewayResult $result): ?string
    {
        $settling = $result->status === GatewayStatus::Succeeded;

        return match (true) {
            ! hash_equals($payment->reference, $result->reference) => 'reference_mismatch',
            $payment->gateway_reference !== null && $result->providerReference !== null
                && ! hash_equals($payment->gateway_reference, $result->providerReference) => 'provider_reference_mismatch',
            $settling && $result->amountMinor === null => 'amount_missing',
            $result->amountMinor !== null && $result->amountMinor !== $payment->amount_minor => 'amount_mismatch',
            $settling && $result->currency === null => 'currency_missing',
            $result->currency !== null && $result->currency !== $payment->currency => 'currency_mismatch',
            default => null,
        };
    }

    protected function log(Request $request, string $gateway, ?PaymentTransaction $payment, bool $signatureValid, string $outcome, ?string $reason): string
    {
        (new PaymentCallback)->forceFill([
            'gateway' => Str::limit($gateway, 32, ''),
            'payment_transaction_id' => $payment?->id,
            'body_hash' => hash('sha256', $request->getContent() ?: http_build_query($request->post())),
            'signature_valid' => $signatureValid,
            'outcome' => $outcome,
            'reason' => $reason ? Str::limit($reason, 190, '') : null,
            'payload' => SensitiveData::strip(array_slice($request->post(), 0, 50, true)),
            'ip_address' => $request->ip(),
        ])->save();

        if ($outcome === PaymentCallback::REJECTED) {
            $this->flagRejected($payment, (string) $reason, $request);
        }

        return $outcome;
    }

    /**
     * Risk signals for review (never acted on automatically).
     */
    protected function flagRejected(?PaymentTransaction $payment, string $reason, Request $request): void
    {
        $risk = app(RiskMonitor::class);
        if ($payment === null) {
            $risk->later('invalid_payment_callback', null, null, ['reason' => $reason, 'ip' => $request->ip()]);
        } elseif (str_contains($reason, 'provider_reference_mismatch')) {
            $risk->later('provider_reference_conflict', $payment->user, $payment, ['reason' => $reason]);
        } else {
            $risk->later('payment_verification_failed', $payment->user, $payment, ['reason' => $reason]);
        }
    }
}
