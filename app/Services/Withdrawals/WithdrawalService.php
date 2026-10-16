<?php

namespace App\Services\Withdrawals;

use App\Contracts\Payments\PayoutGateway;
use App\Contracts\Payments\PayoutRequest;
use App\Contracts\Payments\PayoutResult;
use App\Enums\LedgerTransactionType;
use App\Enums\Permission;
use App\Enums\WithdrawalStatus;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\InvalidCallbackException;
use App\Exceptions\Payments\PayoutRefusedException;
use App\Exceptions\WalletOperationException;
use App\Models\PaymentCallback;
use App\Models\PayoutDestination;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalUpdated;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use App\Services\Security\RiskMonitor;
use App\Services\Wallet\LedgerPosting;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\WalletService;
use App\Support\DisplayTime;
use App\Support\Money;
use App\Support\SensitiveData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

/**
 * Withdrawal lifecycle (see WithdrawalStatus for the state machine).
 *
 * Fund hold: at request time the amount (+ disclosed fee) is DEBITED from the
 * player's wallet in the same transaction that creates the request (amount →
 * gateway_clearing, fee → platform_revenue), under a row lock on the wallet.
 * Held money therefore cannot be spent or withdrawn twice; the balance shown
 * is what remains. Rejection, cancellation, a definitive payout failure or a
 * provider-reported return RELEASES the funds by reversing exactly that ledger
 * transaction — at most once (unique reverses_transaction_id, plus
 * release_ledger_transaction_id on the withdrawal). Records are never deleted.
 *
 * Money only leaves through a configured payout provider; with the default
 * "disabled" driver nothing can reach "processing" or "paid".
 */
class WithdrawalService
{
    public function __construct(
        protected LedgerService $ledger,
        protected WalletService $wallets,
        protected PayoutGatewayManager $gateways,
        protected AuditLogger $audit,
        protected Notifier $notifier,
    ) {}

    /**
     * The disclosed fee for an amount (flat + basis points, rounded down).
     */
    public function fee(int $amountMinor): int
    {
        return (int) config('competition.withdrawals.fee_flat_minor') + intdiv($amountMinor * (int) config('competition.withdrawals.fee_bp'), 10000);
    }

    /**
     * Why this user cannot withdraw this amount now, or null. Used for the review
     * step; request() re-checks everything under the wallet lock.
     */
    public function eligibilityProblem(User $user, int $amountMinor, ?PayoutDestination $destination): ?string
    {
        $limits = config('competition.withdrawals');

        return match (true) {
            ! $limits['enabled'] => 'Withdrawals are not available right now.',
            ! $user->canParticipate() => 'Your account cannot make withdrawals right now.',
            $destination === null || $destination->user_id !== $user->id || $destination->status !== 'active' => 'Choose one of your payout accounts.',
            ! $destination->usable_after->isPast() => 'This payout account was added recently. For your security it can be used from '.DisplayTime::format($destination->usable_after, 'j M, g:i A').' '.DisplayTime::zoneLabel().'.',
            $amountMinor < $limits['min_minor'] || $amountMinor > $limits['max_minor'] => 'Enter an amount between '.Money::format($limits['min_minor']).' and '.Money::format($limits['max_minor']).'.',
            default => null,
        };
    }

    /**
     * Create a withdrawal request and hold its funds. Idempotent per form token.
     *
     * @throws AdministrativeActionException|WalletOperationException
     */
    public function request(User $user, PayoutDestination $destination, int $amountMinor, string $token): Withdrawal
    {
        if ($problem = $this->eligibilityProblem($user, $amountMinor, $destination)) {
            throw new AdministrativeActionException($problem);
        }

        $wallet = $this->wallets->walletFor($user);
        $fee = $this->fee($amountMinor);
        $key = "withdrawal:{$user->id}:{$token}";

        $clearing = $this->wallets->systemWallet(Wallet::SYSTEM_GATEWAY_CLEARING, $wallet->currency);
        $revenue = $this->wallets->systemWallet(Wallet::SYSTEM_PLATFORM_REVENUE, $wallet->currency);

        return DB::transaction(function () use ($user, $wallet, $clearing, $revenue, $destination, $amountMinor, $fee, $key) {
            // Lock every wallet this posting touches, in id order (LedgerService's order), first:
            // serializes this player's money movements without risking a lock-order deadlock.
            Wallet::query()->whereKey([$wallet->id, $clearing->id, $revenue->id])->orderBy('id')->lockForUpdate()->get();

            if ($existing = Withdrawal::query()->where('idempotency_key', $key)->where('user_id', $user->id)->first()) {
                return $existing;
            }

            $limits = config('competition.withdrawals');
            $mine = Withdrawal::query()->where('user_id', $user->id);
            if ((clone $mine)->whereIn('status', WithdrawalStatus::pending())->count() >= $limits['max_pending']) {
                throw new AdministrativeActionException('You already have a withdrawal in progress. Wait for it to finish or cancel it first.');
            }
            $today = (int) (clone $mine)->where('created_at', '>=', now()->subDay())
                ->whereNotIn('status', [WithdrawalStatus::Rejected, WithdrawalStatus::Cancelled, WithdrawalStatus::Failed])->sum('amount_minor');
            if ($today + $amountMinor > $limits['daily_max_minor']) {
                throw new AdministrativeActionException('This would exceed the withdrawal limit of '.Money::format($limits['daily_max_minor']).' per 24 hours.');
            }

            $withdrawal = tap((new Withdrawal)->forceFill([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'payout_destination_id' => $destination->id,
                'destination_snapshot' => $destination->snapshot(),
                'amount_minor' => $amountMinor,
                'fee_minor' => $fee,
                'currency' => $wallet->currency,
                'status' => WithdrawalStatus::Requested,
                'idempotency_key' => $key,
            ]))->save();

            // The hold. Funds, frozen-wallet and restricted-account checks happen here,
            // under the wallet lock; a refusal rolls the request back too.
            $legs = [$wallet->id => -($amountMinor + $fee), $clearing->id => $amountMinor];
            if ($fee > 0) {
                $legs[$revenue->id] = $fee;
            }
            $hold = $this->ledger->post(new LedgerPosting(
                type: LedgerTransactionType::Withdrawal,
                idempotencyKey: 'withdrawal:'.$withdrawal->reference,
                legs: $legs,
                currency: $wallet->currency,
                description: 'Withdrawal '.$withdrawal->reference.' to '.$destination->maskedLabel(),
                related: $withdrawal,
                metadata: ['fee_minor' => $fee],
                actor: $user,
                restrictionsApply: [$wallet->id],
            ));
            $withdrawal->forceFill(['hold_ledger_transaction_id' => $hold->id])->save();

            $this->audit->record('withdrawal.requested', $withdrawal, null, [
                'amount_minor' => $amountMinor, 'fee_minor' => $fee, 'destination' => $withdrawal->destinationLabel(), 'hold_reference' => $hold->reference,
            ], actor: $user);
            $this->notifier->afterCommit($user, new WithdrawalUpdated($withdrawal));
            $this->flagWithdrawalPattern($user, $wallet, $withdrawal);

            return $withdrawal;
        });
    }

    public function cancel(User $user, Withdrawal $withdrawal): Withdrawal
    {
        if ($withdrawal->user_id !== $user->id) {
            throw new AdministrativeActionException('Unknown withdrawal.');
        }

        $withdrawal = $this->transition($withdrawal, WithdrawalStatus::Cancelled, $user, 'Cancelled by the player', ['cancelled_at' => now()]);
        app(RiskMonitor::class)->later('withdrawals_cancelled', $user);

        return $withdrawal;
    }

    public function startReview(User $staff, Withdrawal $withdrawal): Withdrawal
    {
        $this->authorize($staff, Permission::WithdrawalsReview, $withdrawal);

        return $this->transition($withdrawal, WithdrawalStatus::UnderReview, $staff, 'Review started', ['reviewed_by' => $staff->id, 'review_started_at' => now()]);
    }

    public function approve(User $staff, Withdrawal $withdrawal, string $note): Withdrawal
    {
        $this->authorize($staff, Permission::WithdrawalsApprove, $withdrawal);

        return $this->transition($withdrawal, WithdrawalStatus::Approved, $staff, $note, ['approved_by' => $staff->id, 'approved_at' => now()]);
    }

    public function reject(User $staff, Withdrawal $withdrawal, string $reason): Withdrawal
    {
        $this->authorize($staff, Permission::WithdrawalsApprove, $withdrawal);

        return $this->transition($withdrawal, WithdrawalStatus::Rejected, $staff, $reason,
            ['rejected_by' => $staff->id, 'rejected_at' => now(), 'rejection_reason' => mb_substr($reason, 0, 255)]);
    }

    /**
     * Send an approved withdrawal to the payout provider. The withdrawal is claimed as
     * "processing" first (so a double click cannot pay twice), then the provider is
     * called with our reference as its idempotency key.
     */
    public function initiatePayout(User $staff, Withdrawal $withdrawal): Withdrawal
    {
        $this->authorize($staff, Permission::WithdrawalsApprove, $withdrawal);

        try {
            $gateway = $this->gateways->gateway();
        } catch (LogicException $e) {
            throw new AdministrativeActionException('The payout provider is not available: '.$e->getMessage());
        }
        if (! $this->gateways->payoutsAvailable()) {
            throw new AdministrativeActionException('No payout provider is configured, so no money can be sent. The withdrawal stays approved.');
        }

        $withdrawal = $this->transition($withdrawal, WithdrawalStatus::Processing, $staff, 'Payout sent to '.$gateway->name(),
            ['gateway' => $gateway->name(), 'initiated_by' => $staff->id, 'processing_at' => now()]);

        return $this->resend($gateway, $withdrawal);
    }

    /**
     * Ask the provider to pay a processing withdrawal, keyed by our reference (idempotent).
     */
    protected function resend(PayoutGateway $gateway, Withdrawal $withdrawal): Withdrawal
    {
        $destination = $withdrawal->destination()->firstOrFail();
        try {
            $result = $gateway->createPayout(new PayoutRequest(
                reference: $withdrawal->reference,
                amountMinor: $withdrawal->amount_minor,
                currency: $withdrawal->currency,
                method: $destination->method->value,
                provider: $destination->provider,
                accountTitle: $destination->account_title,
                accountNumber: $destination->account_number_encrypted,
                callbackUrl: route('withdrawals.callback', $gateway->name()),
            ));
        } catch (PayoutRefusedException $e) {
            return $this->settle($withdrawal, new PayoutResult(PayoutResult::FAILED, $withdrawal->reference, providerStatus: 'refused', message: $e->getMessage()));
        } catch (GatewayException $e) {
            // Unknown outcome: stays "processing"; withdrawals:reconcile asks the provider.
            Log::warning('Payout request outcome unknown; it will be reconciled.', ['withdrawal' => $withdrawal->reference, 'timeout' => $e->timeout]);
            $withdrawal->forceFill(['provider_status' => $e->timeout ? 'timeout' : 'unavailable'])->save();

            return $withdrawal;
        }

        return $this->settle($withdrawal, $result);
    }

    /**
     * Provider notification. Authenticated by the driver, checked against our
     * record, then confirmed with a status query when the provider offers one.
     */
    public function handleCallback(string $gatewayName, Request $request): string
    {
        try {
            $gateway = $this->gateways->driver($gatewayName);
            $result = $gateway->handleCallback($request);
        } catch (LogicException) {
            return $this->log($request, $gatewayName, null, false, PaymentCallback::REJECTED, 'unknown_gateway');
        } catch (InvalidCallbackException $e) {
            return $this->log($request, $gatewayName, null, $e->signatureValid, PaymentCallback::REJECTED, $e->reason);
        }

        $withdrawal = Withdrawal::query()->where('reference', $result->reference)->where('gateway', $gateway->name())->first();
        if ($withdrawal === null) {
            return $this->log($request, $gatewayName, null, true, PaymentCallback::REJECTED, 'unknown_withdrawal');
        }
        if ($mismatch = $this->mismatch($withdrawal, $result)) {
            return $this->log($request, $gatewayName, $withdrawal, true, PaymentCallback::REJECTED, $mismatch);
        }

        if ($gateway->supportsStatusQuery()) {
            try {
                $result = $gateway->queryPayout($withdrawal);
            } catch (GatewayException) {
                return $this->log($request, $gatewayName, $withdrawal, true, PaymentCallback::PENDING, 'verification_unavailable');
            }
            if ($mismatch = $this->mismatch($withdrawal, $result)) {
                return $this->log($request, $gatewayName, $withdrawal, true, PaymentCallback::REJECTED, 'verification_'.$mismatch);
            }
        }

        $before = $withdrawal->status;
        $after = $this->settle($withdrawal, $result)->status;

        return $this->log($request, $gatewayName, $withdrawal, true, $after === $before ? PaymentCallback::DUPLICATE : PaymentCallback::APPLIED, $result->status);
    }

    /**
     * Ask the provider about a processing (or paid) payout and apply its answer.
     */
    public function refresh(Withdrawal $withdrawal): Withdrawal
    {
        if (! in_array($withdrawal->status, [WithdrawalStatus::Processing, WithdrawalStatus::Paid], true) || $withdrawal->gateway === null) {
            return $withdrawal;
        }

        try {
            $gateway = $this->gateways->driver($withdrawal->gateway);
            if (! $gateway->supportsStatusQuery()) {
                return $withdrawal;
            }
            $result = $gateway->queryPayout($withdrawal);
        } catch (LogicException|GatewayException) {
            return $withdrawal;
        }

        // The payout request timed out before the provider recorded it: send it again with the
        // SAME reference (the provider's idempotency key), so it can never be paid twice.
        if ($result->status === PayoutResult::NOT_FOUND && $withdrawal->status === WithdrawalStatus::Processing && $withdrawal->provider_reference === null) {
            return $this->resend($gateway, $withdrawal);
        }

        if ($result->status === PayoutResult::NOT_FOUND || $this->mismatch($withdrawal, $result)) {
            return $withdrawal; // never conclude anything from an unknown or inconsistent answer
        }

        return $this->settle($withdrawal, $result);
    }

    /**
     * Apply a provider outcome. Idempotent: repeated or late answers change nothing.
     */
    public function settle(Withdrawal $withdrawal, PayoutResult $result): Withdrawal
    {
        $target = match ($result->status) {
            PayoutResult::PAID => WithdrawalStatus::Paid,
            PayoutResult::FAILED => WithdrawalStatus::Failed,
            PayoutResult::REVERSED => WithdrawalStatus::Reversed,
            default => null,
        };

        $current = Withdrawal::query()->findOrFail($withdrawal->id)->status;
        if ($target === null || $current === $target || ! $current->canTransitionTo($target)) {
            if ($result->providerReference && $withdrawal->provider_reference === null) {
                $withdrawal->forceFill(['provider_reference' => $result->providerReference, 'provider_status' => $result->providerStatus])->save();
            }

            return $withdrawal->fresh();
        }

        try {
            return $this->applyOutcome($withdrawal, $target, $result);
        } catch (AdministrativeActionException) {
            // A concurrent duplicate notification settled it first: nothing more to do.
            return $withdrawal->fresh();
        }
    }

    protected function applyOutcome(Withdrawal $withdrawal, WithdrawalStatus $target, PayoutResult $result): Withdrawal
    {
        return $this->transition($withdrawal, $target, null, $result->message ?? 'Provider status: '.$result->providerStatus, array_filter([
            'provider_reference' => $withdrawal->provider_reference ?? $result->providerReference,
            'provider_status' => $result->providerStatus,
            'paid_at' => $target === WithdrawalStatus::Paid ? now() : null,
            'failed_at' => $target === WithdrawalStatus::Failed ? now() : null,
            'failure_reason' => $target === WithdrawalStatus::Failed ? mb_substr((string) ($result->message ?? 'The payout failed at the provider.'), 0, 255) : null,
            'reversed_at' => $target === WithdrawalStatus::Reversed ? now() : null,
        ], fn ($v) => $v !== null));
    }

    // ---- internals --------------------------------------------------------------------

    /**
     * Deposit-then-withdraw without playing is a classic money-movement pattern; it is
     * flagged for review only (it is often innocent).
     */
    protected function flagWithdrawalPattern(User $user, Wallet $wallet, Withdrawal $withdrawal): void
    {
        $lastDeposit = DB::table('ledger_entries as e')->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')
            ->where('e.wallet_id', $wallet->id)->where('t.type', LedgerTransactionType::Deposit->value)
            ->where('t.created_at', '>=', now()->subDay())->max('t.created_at');
        if ($lastDeposit === null) {
            return;
        }

        $playedSince = DB::table('ledger_entries as e')->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')
            ->where('e.wallet_id', $wallet->id)->where('t.type', LedgerTransactionType::EntryFee->value)
            ->where('t.created_at', '>=', $lastDeposit)->exists();
        if (! $playedSince) {
            app(RiskMonitor::class)->later('withdrawal_without_play', $user, $withdrawal, ['amount_minor' => $withdrawal->amount_minor]);
        }
    }

    /**
     * The only place a withdrawal changes state: locked, validated against the
     * state machine, audited, and releasing the held funds when the new state requires it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function transition(Withdrawal $withdrawal, WithdrawalStatus $to, ?User $actor, string $reason, array $attributes = []): Withdrawal
    {
        $updated = DB::transaction(function () use ($withdrawal, $to, $actor, $reason, $attributes) {
            // First statement: lock the withdrawal. A release then locks wallets in id order
            // inside LedgerService; nothing locks a withdrawal while holding a wallet.
            $locked = Withdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new AdministrativeActionException("A {$from->label()} withdrawal cannot become {$to->label()}.");
            }

            $locked->forceFill(['status' => $to] + $attributes)->save();

            if ($to->releasesFunds()) {
                $this->release($locked, $reason, $actor);
            }

            $this->audit->record('withdrawal.'.$to->value, $locked, ['status' => $from->value], ['status' => $to->value] + array_intersect_key($attributes, array_flip(['provider_reference', 'provider_status'])),
                ['reason' => $reason, 'amount_minor' => $locked->amount_minor, 'destination' => $locked->destinationLabel()], $actor);
            $this->notifier->afterCommit(fn () => $locked->user, new WithdrawalUpdated($locked));

            return $locked;
        });

        return $updated->fresh();
    }

    /**
     * Return the held funds: reverse the hold transaction, exactly once.
     */
    protected function release(Withdrawal $withdrawal, string $reason, ?User $actor): void
    {
        if ($withdrawal->release_ledger_transaction_id !== null || $withdrawal->hold_ledger_transaction_id === null) {
            return;
        }

        $release = $this->wallets->refund($withdrawal->holdTransaction()->firstOrFail(), 'Withdrawal '.$withdrawal->reference.' '.$withdrawal->status->label().': '.Str::limit($reason, 150), $actor);
        $withdrawal->forceFill(['release_ledger_transaction_id' => $release->id])->save();
    }

    protected function mismatch(Withdrawal $withdrawal, PayoutResult $result): ?string
    {
        return match (true) {
            ! hash_equals($withdrawal->reference, $result->reference) => 'reference_mismatch',
            $withdrawal->provider_reference !== null && $result->providerReference !== null
                && ! hash_equals($withdrawal->provider_reference, $result->providerReference) => 'provider_reference_mismatch',
            $result->amountMinor !== null && $result->amountMinor !== $withdrawal->amount_minor => 'amount_mismatch',
            $result->currency !== null && $result->currency !== $withdrawal->currency => 'currency_mismatch',
            default => null,
        };
    }

    /**
     * Finance permission, and four eyes: staff never act on their own withdrawal.
     */
    protected function authorize(User $staff, Permission $permission, Withdrawal $withdrawal): void
    {
        if (! $staff->hasPermission($permission)) {
            throw new AdministrativeActionException('You are not allowed to do this.');
        }
        if ($withdrawal->user_id === $staff->id) {
            throw new AdministrativeActionException('You cannot review your own withdrawal.');
        }
    }

    protected function log(Request $request, string $gateway, ?Withdrawal $withdrawal, bool $signatureValid, string $outcome, ?string $reason): string
    {
        (new PaymentCallback)->forceFill([
            'gateway' => Str::limit($gateway, 32, ''),
            'withdrawal_id' => $withdrawal?->id,
            'body_hash' => hash('sha256', $request->getContent() ?: http_build_query($request->post())),
            'signature_valid' => $signatureValid,
            'outcome' => $outcome,
            'reason' => $reason ? Str::limit($reason, 190, '') : null,
            'payload' => SensitiveData::strip(array_slice($request->post(), 0, 50, true)),
            'ip_address' => $request->ip(),
        ])->save();

        if ($outcome === PaymentCallback::REJECTED) {
            $withdrawal
                ? app(RiskMonitor::class)->later(str_contains((string) $reason, 'provider_reference_mismatch') ? 'provider_reference_conflict' : 'payment_verification_failed', $withdrawal->user, $withdrawal, ['reason' => $reason])
                : app(RiskMonitor::class)->later('invalid_payment_callback', null, null, ['reason' => $reason, 'ip' => $request->ip()]);
        }

        return $outcome;
    }
}
