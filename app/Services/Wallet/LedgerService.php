<?php

namespace App\Services\Wallet;

use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Exceptions\WalletOperationException;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONLY writer of ledger rows and wallet balances.
 *
 * Every money movement is one balanced, append-only ledger transaction whose
 * entries sum to zero. wallets.balance_minor is a cache of the entries,
 * updated in the same database transaction; the ledger is the source of truth
 * (see WalletReconciler).
 *
 * Concurrency: the wallets involved are locked (SELECT ... FOR UPDATE) in
 * ascending id order as the FIRST statement of the transaction, so two
 * debits of one wallet run one after the other and see each other's balance,
 * and lock order is consistent (no deadlock between postings).
 *
 * Idempotency: idempotency_key is unique in the database. A retry with the
 * same key and the same details returns the original transaction and moves
 * no money; the same key with different details is refused.
 */
class LedgerService
{
    /**
     * Metadata keys that must never be stored (payment secrets, credentials).
     */
    protected const FORBIDDEN_METADATA = '/(^|_)(password|passwd|secret|token|pin|cvv|cvc|card|card_?number|pan|otp|api_?key|authori[sz]ation|credentials?)(_|$)/i';

    /**
     * @param  Closure(LedgerTransaction): void|null  $within  runs inside the same database
     *                                                         transaction, after the entries are written; throwing rolls everything back
     */
    public function post(LedgerPosting $posting, ?Closure $within = null): LedgerTransaction
    {
        $this->validate($posting);

        try {
            return DB::transaction(fn () => $this->write($posting, $within), 3);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key committed first.
            $existing = LedgerTransaction::query()->where('idempotency_key', $posting->idempotencyKey)->first();

            if ($existing === null) {
                throw $e;
            }

            return $this->replay($existing, $posting);
        }
    }

    protected function write(LedgerPosting $posting, ?Closure $within): LedgerTransaction
    {
        // First statement: lock every wallet involved, in id order.
        $wallets = Wallet::query()->whereIn('id', array_keys($posting->legs))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $existing = LedgerTransaction::query()->where('idempotency_key', $posting->idempotencyKey)->lockForUpdate()->first();
        if ($existing !== null) {
            return $this->replay($existing, $posting);
        }

        if ($wallets->count() !== count($posting->legs)) {
            throw WalletOperationException::invalidOperation('A wallet in this transaction does not exist.');
        }

        if ($posting->reverses !== null && LedgerTransaction::query()->where('reverses_transaction_id', $posting->reverses)->lockForUpdate()->exists()) {
            throw WalletOperationException::alreadyReversed();
        }

        foreach ($posting->legs as $walletId => $amount) {
            $this->assertCanMove($wallets[$walletId], $amount, $posting);
        }

        $transaction = (new LedgerTransaction)->forceFill([
            'idempotency_key' => $posting->idempotencyKey,
            'type' => $posting->type,
            'currency' => $posting->currency,
            'description' => $posting->description,
            'reference_type' => $posting->related?->getMorphClass(),
            'reference_id' => $posting->related?->getKey(),
            'reverses_transaction_id' => $posting->reverses,
            'created_by' => $posting->actor?->id,
            'metadata' => $posting->metadata ?: null,
        ]);
        $transaction->save();

        foreach ($wallets as $wallet) {
            $balance = $wallet->balance_minor + $posting->legs[$wallet->id];

            (new LedgerEntry)->forceFill([
                'ledger_transaction_id' => $transaction->id,
                'wallet_id' => $wallet->id,
                'amount_minor' => $posting->legs[$wallet->id],
                'balance_after_minor' => $balance,
            ])->save();

            $wallet->forceFill(['balance_minor' => $balance])->save();
        }

        if ($within !== null) {
            $within($transaction);
        }

        return $transaction;
    }

    protected function assertCanMove(Wallet $wallet, int $amount, LedgerPosting $posting): void
    {
        if ($wallet->currency !== $posting->currency) {
            throw WalletOperationException::currencyMismatch();
        }

        // Credits are always accepted; system wallets may go negative (e.g. a sponsored prize pool).
        if ($amount > 0 || $wallet->type === WalletType::System) {
            return;
        }

        if (in_array($wallet->id, $posting->restrictionsApply, true)) {
            if ($wallet->status !== WalletStatus::Active) {
                throw WalletOperationException::walletRestricted();
            }

            if (! $wallet->user?->canParticipate()) {
                throw WalletOperationException::accountRestricted();
            }
        }

        if ($wallet->balance_minor + $amount < 0) {
            throw WalletOperationException::insufficientFunds();
        }
    }

    /**
     * Return a previously posted transaction for a retried request, but only
     * when the request is the same one.
     */
    protected function replay(LedgerTransaction $existing, LedgerPosting $posting): LedgerTransaction
    {
        $legs = $existing->entries()->pluck('amount_minor', 'wallet_id')->map(fn ($a) => (int) $a)->sortKeys()->all();
        $requested = $posting->legs;
        ksort($requested);

        if ($existing->type !== $posting->type || $existing->currency !== $posting->currency || $legs !== $requested
            || $existing->reverses_transaction_id !== $posting->reverses) {
            throw WalletOperationException::idempotencyConflict();
        }

        return $existing;
    }

    protected function validate(LedgerPosting $posting): void
    {
        if (count($posting->legs) < 2) {
            throw new InvalidArgumentException('A ledger transaction needs at least two wallets.');
        }

        foreach ($posting->legs as $walletId => $amount) {
            if (! is_int($walletId) || ! is_int($amount) || $amount === 0) {
                throw WalletOperationException::invalidAmount();
            }
        }

        if (array_sum($posting->legs) !== 0) {
            throw new InvalidArgumentException('Ledger entries must sum to zero.');
        }

        if ($posting->idempotencyKey === '' || strlen($posting->idempotencyKey) > 191) {
            throw new InvalidArgumentException('An idempotency key of 1–191 characters is required.');
        }

        $this->assertSafeMetadata($posting->metadata);
    }

    /**
     * @param  array<mixed>  $metadata
     */
    protected function assertSafeMetadata(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && preg_match(self::FORBIDDEN_METADATA, $key)) {
                throw new InvalidArgumentException("Ledger metadata may not contain \"{$key}\".");
            }

            if (is_array($value)) {
                $this->assertSafeMetadata($value);
            }
        }
    }
}
