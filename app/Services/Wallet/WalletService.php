<?php

namespace App\Services\Wallet;

use App\Enums\LedgerTransactionType;
use App\Enums\ParticipantStatus;
use App\Enums\Permission;
use App\Enums\RoomStatus;
use App\Enums\WalletType;
use App\Exceptions\WalletOperationException;
use App\Models\LedgerTransaction;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\EntryFeeRefunded;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use App\Services\Security\RiskMonitor;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Wallet operations for player accounts. Controllers and other services move
 * money only through this class (which posts through LedgerService); nothing
 * writes balances directly.
 *
 * Each player-wallet movement has a platform counterparty, so every ledger
 * transaction balances:
 *   deposit / withdrawal      <-> gateway_clearing
 *   entry fee / entry refund  <-> prize_pool
 *   prize payout              <-> prize_pool
 *   adjustment                <-> adjustments
 */
class WalletService
{
    /**
     * @var array<string, list<LedgerTransactionType>>
     */
    protected const ALLOWED = [
        'credit' => [LedgerTransactionType::Deposit, LedgerTransactionType::EntryRefund, LedgerTransactionType::PrizePayout, LedgerTransactionType::Adjustment],
        'debit' => [LedgerTransactionType::Withdrawal, LedgerTransactionType::EntryFee, LedgerTransactionType::Adjustment],
    ];

    public function __construct(
        protected LedgerService $ledger,
        protected AuditLogger $audit,
    ) {}

    /**
     * Add money to a player wallet. Credits are accepted even on frozen
     * wallets (refunds and prizes are owed regardless).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function credit(Wallet $wallet, int $amountMinor, LedgerTransactionType $type, string $idempotencyKey, ?string $description = null, ?Model $related = null, array $metadata = [], ?User $actor = null): LedgerTransaction
    {
        return $this->move('credit', $wallet, $amountMinor, $type, $idempotencyKey, $description, $related, $metadata, $actor);
    }

    /**
     * Take money from a player wallet. Refused (and nothing written) when the
     * balance is too low, the wallet is frozen, the account is restricted,
     * or the currency differs. A retry with the same key moves nothing.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws WalletOperationException
     */
    public function debit(Wallet $wallet, int $amountMinor, LedgerTransactionType $type, string $idempotencyKey, ?string $description = null, ?Model $related = null, array $metadata = [], ?User $actor = null): LedgerTransaction
    {
        return $this->move('debit', $wallet, $amountMinor, $type, $idempotencyKey, $description, $related, $metadata, $actor);
    }

    /**
     * Reverse a whole transaction (full refunds only). Entry fees become
     * "entry_refund", anything else a "reversal". A transaction can be
     * reversed at most once; repeating the call returns the same reversal.
     *
     * @throws WalletOperationException
     */
    public function refund(LedgerTransaction $original, string $reason, ?User $actor = null, ?\Closure $within = null): LedgerTransaction
    {
        if ($original->type === LedgerTransactionType::Reversal || $original->reverses_transaction_id !== null) {
            throw WalletOperationException::invalidOperation('A reversal cannot itself be reversed.');
        }

        $legs = $original->entries()->pluck('amount_minor', 'wallet_id')->map(fn ($a) => -(int) $a)->all();

        return $this->ledger->post(new LedgerPosting(
            type: $original->type === LedgerTransactionType::EntryFee ? LedgerTransactionType::EntryRefund : LedgerTransactionType::Reversal,
            idempotencyKey: 'reversal:'.$original->id,
            legs: $legs,
            currency: $original->currency,
            description: $reason,
            related: $original->reference_type ? $original->related : null,
            metadata: ['reverses' => $original->reference],
            actor: $actor,
            reverses: $original->id,
        ), $within);
    }

    /**
     * Money the player can spend now. Withdrawals (later phase) debit the
     * wallet when requested and are reversed if they fail, so there are no
     * holds and the available balance equals the ledger balance.
     */
    public function getAvailableBalance(User|Wallet $owner): int
    {
        $wallet = $owner instanceof User ? $this->walletFor($owner) : $owner;

        return (int) Wallet::query()->whereKey($wallet->id)->value('balance_minor');
    }

    /**
     * The player's wallet in the platform currency, created if missing.
     */
    public function walletFor(User $user): Wallet
    {
        $currency = config('competition.currency.code');
        $find = fn () => Wallet::query()->where('type', WalletType::User)->where('user_id', $user->id)->where('currency', $currency)->first();

        if ($wallet = $find()) {
            return $wallet;
        }

        try {
            return tap((new Wallet)->forceFill(['type' => WalletType::User, 'user_id' => $user->id, 'currency' => $currency]))->save();
        } catch (UniqueConstraintViolationException) {
            return $find();
        }
    }

    /**
     * Charge a seat's frozen entry fee to its player's wallet. Validates the
     * competition side under the room lock: paid entries enabled, a paid room
     * still taking entries, and a reserved seat that has not been charged.
     *
     * @throws WalletOperationException
     */
    public function chargeEntryFee(RoomParticipant $seat): LedgerTransaction
    {
        return DB::transaction(function () use ($seat) {
            $room = Room::query()->lockForUpdate()->findOrFail($seat->room_id);
            $seat = RoomParticipant::query()->lockForUpdate()->findOrFail($seat->id);

            if (! config('competition.paid_entries.enabled')) {
                throw WalletOperationException::disabled('Paid entries are disabled.');
            }

            if ($seat->entry_fee_minor <= 0) {
                throw WalletOperationException::invalidOperation('This seat has no entry fee.');
            }

            $key = $this->entryFeeKey($seat);
            $charged = $seat->entry_ledger_transaction_id ? LedgerTransaction::query()->find($seat->entry_ledger_transaction_id) : null;
            if ($charged?->idempotency_key === $key) {
                return $charged;
            }

            if ($seat->status !== ParticipantStatus::Reserved || ! in_array($room->status, [RoomStatus::Open, RoomStatus::Full], true)) {
                throw WalletOperationException::invalidOperation('This seat cannot be paid for now.');
            }

            $wallet = $this->walletFor($seat->user()->firstOrFail());

            return $this->ledger->post(new LedgerPosting(
                type: LedgerTransactionType::EntryFee,
                idempotencyKey: $key,
                legs: [$wallet->id => -$seat->entry_fee_minor, $this->systemWallet(Wallet::SYSTEM_PRIZE_POOL, $wallet->currency)->id => $seat->entry_fee_minor],
                currency: $wallet->currency,
                description: 'Entry fee: '.$room->name,
                related: $seat,
                metadata: ['room_id' => $room->id],
                restrictionsApply: [$wallet->id],
            ), fn (LedgerTransaction $tx) => $seat->forceFill(['entry_ledger_transaction_id' => $tx->id, 'refund_ledger_transaction_id' => null, 'refunded_at' => null])->save());
        });
    }

    /**
     * Refund a seat's captured entry fee in full. Returns null when nothing
     * was charged; repeating the call returns the same refund.
     */
    public function refundEntryFee(RoomParticipant $seat, string $reason): ?LedgerTransaction
    {
        return DB::transaction(function () use ($seat, $reason) {
            Room::query()->lockForUpdate()->findOrFail($seat->room_id);
            $seat = RoomParticipant::query()->lockForUpdate()->findOrFail($seat->id);

            $charge = $seat->entry_ledger_transaction_id ? LedgerTransaction::query()->find($seat->entry_ledger_transaction_id) : null;
            if ($charge === null) {
                return null;
            }

            // Exactly the amount charged (the original entries, reversed); a second call returns
            // the same refund because a ledger transaction can be reversed only once.
            $refund = $this->refund($charge, 'Entry fee refund: '.$reason, null,
                fn (LedgerTransaction $tx) => $seat->forceFill(['refund_ledger_transaction_id' => $tx->id, 'refunded_at' => now()])->save());

            // Recovery: the refund exists but the seat lost its link (e.g. a crash or manual edit
            // between the two writes). Re-link the existing refund; never post a second one.
            if ($seat->refund_ledger_transaction_id === null) {
                $seat->forceFill(['refund_ledger_transaction_id' => $refund->id, 'refunded_at' => $seat->refunded_at ?? now()])->save();
            }

            if ($refund->wasRecentlyCreated) {
                $this->audit->record('room.entry_refunded', $seat, null, [
                    'amount_minor' => $seat->entry_fee_minor,
                    'entry_reference' => $charge->reference,
                    'refund_reference' => $refund->reference,
                ], ['room_id' => $seat->room_id, 'reason' => $reason]);

                app(RiskMonitor::class)->later('repeated_entry_refunds', $seat->user()->first(), null, ['room_id' => $seat->room_id]);
                app(Notifier::class)->afterCommit(fn () => $seat->user,
                    new EntryFeeRefunded(Room::query()->findOrFail($seat->room_id), $seat->entry_fee_minor, $refund->reference));
            }

            return $refund;
        });
    }

    /**
     * A controlled administrative correction: authorized staff, a written
     * reason, a per-form idempotency token, an upper limit, and an audit
     * record in the same database transaction. There is no "set balance".
     *
     * @throws WalletOperationException
     */
    public function adjust(User $actor, Wallet $wallet, int $signedAmountMinor, string $reason, string $token): LedgerTransaction
    {
        if (! config('competition.wallet.adjustments_enabled')) {
            throw WalletOperationException::disabled('Wallet adjustments are disabled on this platform.');
        }

        if (! $actor->hasPermission(Permission::FinanceAdjust) || $wallet->user_id === $actor->id) {
            throw WalletOperationException::invalidOperation('You are not allowed to adjust this wallet.');
        }

        $limit = (int) config('competition.wallet.max_adjustment_minor');
        if ($signedAmountMinor === 0 || abs($signedAmountMinor) > $limit) {
            throw WalletOperationException::invalidAmount('The adjustment must be non-zero and at most '.Money::format($limit).'.');
        }

        $type = $signedAmountMinor > 0 ? 'credit' : 'debit';

        return DB::transaction(function () use ($actor, $wallet, $signedAmountMinor, $reason, $token, $type) {
            $transaction = $this->move($type, $wallet, abs($signedAmountMinor), LedgerTransactionType::Adjustment, 'adjustment:'.$token,
                'Adjustment: '.$reason, null, ['reason' => $reason], $actor, restricted: false);

            if ($transaction->wasRecentlyCreated) {
                $after = (int) $transaction->entries()->where('wallet_id', $wallet->id)->value('balance_after_minor');
                $before = $after - $signedAmountMinor;
                $this->audit->record('wallet.adjusted', $wallet, ['balance_minor' => $before], ['balance_minor' => $after],
                    ['reason' => $reason, 'amount_minor' => $signedAmountMinor, 'ledger_reference' => $transaction->reference], $actor);
            }

            return $transaction;
        });
    }

    public function systemWallet(string $code, string $currency): Wallet
    {
        $find = fn () => Wallet::query()->where('type', WalletType::System)->where('system_code', $code)->where('currency', $currency)->first();

        if ($wallet = $find()) {
            return $wallet;
        }

        try {
            return tap((new Wallet)->forceFill(['type' => WalletType::System, 'system_code' => $code, 'currency' => $currency]))->save();
        } catch (UniqueConstraintViolationException) {
            return $find();
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function move(string $direction, Wallet $wallet, int $amountMinor, LedgerTransactionType $type, string $key, ?string $description, ?Model $related, array $metadata, ?User $actor, bool $restricted = true): LedgerTransaction
    {
        if ($wallet->type !== WalletType::User) {
            throw WalletOperationException::invalidOperation('Only player wallets can be credited or debited directly.');
        }

        if ($amountMinor <= 0) {
            throw WalletOperationException::invalidAmount();
        }

        if (! in_array($type, self::ALLOWED[$direction], true)) {
            throw WalletOperationException::invalidOperation("A {$type->value} cannot be a {$direction}.");
        }

        $counterparty = $this->systemWallet(self::counterparty($type), $wallet->currency);
        $signed = $direction === 'credit' ? $amountMinor : -$amountMinor;

        return $this->ledger->post(new LedgerPosting(
            type: $type,
            idempotencyKey: $key,
            legs: [$wallet->id => $signed, $counterparty->id => -$signed],
            currency: $wallet->currency,
            description: $description,
            related: $related,
            metadata: $metadata,
            actor: $actor,
            restrictionsApply: $restricted ? [$wallet->id] : [],
        ));
    }

    public static function counterparty(LedgerTransactionType $type): string
    {
        return match ($type) {
            LedgerTransactionType::Deposit, LedgerTransactionType::Withdrawal => Wallet::SYSTEM_GATEWAY_CLEARING,
            LedgerTransactionType::EntryFee, LedgerTransactionType::EntryRefund, LedgerTransactionType::PrizePayout => Wallet::SYSTEM_PRIZE_POOL,
            LedgerTransactionType::Adjustment, LedgerTransactionType::Reversal => Wallet::SYSTEM_ADJUSTMENTS,
            LedgerTransactionType::PlatformFee => Wallet::SYSTEM_PLATFORM_REVENUE,
        };
    }

    protected function entryFeeKey(RoomParticipant $seat): string
    {
        // One charge per seat per join (a seat row is reused if the player rejoins).
        return 'entry_fee:'.$seat->id.':'.$seat->joined_at?->format('YmdHisv');
    }
}
