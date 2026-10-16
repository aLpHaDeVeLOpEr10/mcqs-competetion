<?php

namespace Tests\Feature\Wallet;

use App\Enums\LedgerTransactionType;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\UserStatus;
use App\Enums\WalletStatus;
use App\Exceptions\WalletOperationException;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\LedgerPosting;
use App\Services\Wallet\LedgerService;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallets = app(WalletService::class);
    }

    protected function player(int $balance = 0, array $user = []): Wallet
    {
        $wallet = $this->wallets->walletFor(User::factory()->create($user));

        if ($balance > 0) {
            $this->wallets->credit($wallet, $balance, LedgerTransactionType::Deposit, 'seed:'.$wallet->id);
        }

        return $wallet->refresh();
    }

    protected function balance(Wallet $wallet): int
    {
        return $this->wallets->getAvailableBalance($wallet);
    }

    protected function assertNothingWritten(int $transactions, int $entries): void
    {
        $this->assertSame($transactions, LedgerTransaction::query()->count(), 'No ledger transaction may be written.');
        $this->assertSame($entries, LedgerEntry::query()->count(), 'No ledger entry may be written.');
    }

    public function test_credit_records_a_balanced_transaction_and_updates_the_balance(): void
    {
        $wallet = $this->player();

        $tx = $this->wallets->credit($wallet, 150_000, LedgerTransactionType::Deposit, 'deposit:1', 'Test deposit');

        $this->assertSame(150_000, $this->balance($wallet));
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $tx->reference);
        $this->assertSame(LedgerTransactionType::Deposit, $tx->type);
        $this->assertSame('PKR', $tx->currency);
        $this->assertSame('completed', $tx->status());

        $entries = $tx->entries()->get()->keyBy('wallet_id');
        $this->assertCount(2, $entries);
        $this->assertSame(0, (int) $entries->sum('amount_minor'));
        $this->assertSame(150_000, $entries[$wallet->id]->amount_minor);
        $this->assertSame('credit', $entries[$wallet->id]->direction());
        $this->assertSame(150_000, $entries[$wallet->id]->balance_after_minor);

        $clearing = Wallet::query()->where('system_code', Wallet::SYSTEM_GATEWAY_CLEARING)->firstOrFail();
        $this->assertSame(-150_000, $clearing->balance_minor);
    }

    public function test_debit_reduces_the_balance(): void
    {
        $wallet = $this->player(10_000);

        $tx = $this->wallets->debit($wallet, 2_550, LedgerTransactionType::Withdrawal, 'withdrawal:1');

        $this->assertSame(7_450, $this->balance($wallet));
        $entry = $tx->entries()->where('wallet_id', $wallet->id)->firstOrFail();
        $this->assertSame(-2_550, $entry->amount_minor);
        $this->assertSame('debit', $entry->direction());
        $this->assertSame(7_450, $entry->balance_after_minor);
    }

    public function test_debit_fails_on_insufficient_funds_and_writes_nothing(): void
    {
        $wallet = $this->player(1_000);
        [$tx, $entries] = [LedgerTransaction::query()->count(), LedgerEntry::query()->count()];

        try {
            $this->wallets->debit($wallet, 1_001, LedgerTransactionType::EntryFee, 'fee:1');
            $this->fail('Expected insufficient funds.');
        } catch (WalletOperationException $e) {
            $this->assertSame(WalletOperationException::INSUFFICIENT_FUNDS, $e->reason);
        }

        $this->assertSame(1_000, $this->balance($wallet));
        $this->assertNothingWritten($tx, $entries);

        // Spending exactly the balance is fine.
        $this->wallets->debit($wallet, 1_000, LedgerTransactionType::EntryFee, 'fee:2');
        $this->assertSame(0, $this->balance($wallet));
    }

    public function test_debit_fails_for_frozen_wallets_and_restricted_accounts_but_credits_still_arrive(): void
    {
        $frozen = $this->player(5_000);
        $frozen->forceFill(['status' => WalletStatus::Frozen])->save();

        $this->assertRefused(WalletOperationException::WALLET_RESTRICTED, fn () => $this->wallets->debit($frozen, 100, LedgerTransactionType::EntryFee, 'f:1'));
        $this->wallets->credit($frozen, 100, LedgerTransactionType::EntryRefund, 'r:1');
        $this->assertSame(5_100, $this->balance($frozen));

        foreach ([UserStatus::Restricted, UserStatus::Suspended] as $status) {
            $wallet = $this->player(5_000);
            $wallet->user->forceFill(['status' => $status])->save();
            $this->assertRefused(WalletOperationException::ACCOUNT_RESTRICTED, fn () => $this->wallets->debit($wallet, 100, LedgerTransactionType::Withdrawal, 'w:'.$status->value));
            $this->assertSame(5_000, $this->balance($wallet));
        }
    }

    public function test_incompatible_currency_is_refused(): void
    {
        $pkr = $this->player(5_000);
        $usd = (new Wallet)->forceFill(['type' => 'system', 'system_code' => 'usd_test', 'currency' => 'USD']);
        $usd->save();
        $count = LedgerTransaction::query()->count();

        $this->assertRefused(WalletOperationException::CURRENCY_MISMATCH, fn () => app(LedgerService::class)->post(new LedgerPosting(
            LedgerTransactionType::Adjustment, 'fx:1', [$pkr->id => -100, $usd->id => 100], 'PKR')));

        $this->assertSame(5_000, $this->balance($pkr));
        $this->assertSame($count, LedgerTransaction::query()->count());
    }

    public function test_invalid_amounts_and_directions_are_refused(): void
    {
        $wallet = $this->player(5_000);

        $this->assertRefused(WalletOperationException::INVALID_AMOUNT, fn () => $this->wallets->debit($wallet, 0, LedgerTransactionType::EntryFee, 'a'));
        $this->assertRefused(WalletOperationException::INVALID_AMOUNT, fn () => $this->wallets->credit($wallet, -5, LedgerTransactionType::Deposit, 'b'));
        $this->assertRefused(WalletOperationException::INVALID_OPERATION, fn () => $this->wallets->credit($wallet, 5, LedgerTransactionType::EntryFee, 'c'));
        $this->assertRefused(WalletOperationException::INVALID_OPERATION, fn () => $this->wallets->debit($wallet, 5, LedgerTransactionType::PrizePayout, 'd'));

        $system = Wallet::query()->where('system_code', Wallet::SYSTEM_GATEWAY_CLEARING)->firstOrFail();
        $this->assertRefused(WalletOperationException::INVALID_OPERATION, fn () => $this->wallets->credit($system, 5, LedgerTransactionType::Deposit, 'e'));

        $this->expectException(InvalidArgumentException::class);
        app(LedgerService::class)->post(new LedgerPosting(LedgerTransactionType::Adjustment, 'unbalanced', [$wallet->id => 100, $system->id => -99], 'PKR'));
    }

    public function test_a_duplicate_idempotency_key_moves_money_once(): void
    {
        $wallet = $this->player(10_000);

        $first = $this->wallets->debit($wallet, 3_000, LedgerTransactionType::EntryFee, 'fee:room-1:seat-1');
        $retry = $this->wallets->debit($wallet, 3_000, LedgerTransactionType::EntryFee, 'fee:room-1:seat-1');

        $this->assertTrue($first->is($retry));
        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($retry->wasRecentlyCreated);
        $this->assertSame(7_000, $this->balance($wallet));
        $this->assertSame(1, LedgerTransaction::query()->where('idempotency_key', 'fee:room-1:seat-1')->count());

        // Same key, different request: refused rather than silently returning the old result.
        $this->assertRefused(WalletOperationException::IDEMPOTENCY_CONFLICT, fn () => $this->wallets->debit($wallet, 3_500, LedgerTransactionType::EntryFee, 'fee:room-1:seat-1'));
        $this->assertRefused(WalletOperationException::IDEMPOTENCY_CONFLICT, fn () => $this->wallets->credit($wallet, 3_000, LedgerTransactionType::Deposit, 'fee:room-1:seat-1'));
        $this->assertSame(7_000, $this->balance($wallet));
    }

    public function test_a_retried_request_succeeds_even_after_the_balance_was_spent(): void
    {
        $wallet = $this->player(1_000);
        $first = $this->wallets->debit($wallet, 1_000, LedgerTransactionType::EntryFee, 'fee:x');

        // The balance is now 0; a retry must return the original, not fail for funds or debit again.
        $this->assertTrue($first->is($this->wallets->debit($wallet, 1_000, LedgerTransactionType::EntryFee, 'fee:x')));
        $this->assertSame(0, $this->balance($wallet));
    }

    public function test_refund_reverses_a_transaction_exactly_once(): void
    {
        $wallet = $this->player(10_000);
        $withdrawal = $this->wallets->debit($wallet, 4_000, LedgerTransactionType::Withdrawal, 'withdrawal:9');

        $refund = $this->wallets->refund($withdrawal, 'Bank transfer failed');

        $this->assertSame(LedgerTransactionType::Reversal, $refund->type);
        $this->assertSame($withdrawal->id, $refund->reverses_transaction_id);
        $this->assertSame(10_000, $this->balance($wallet));
        $this->assertSame('reversed', $withdrawal->fresh()->status());
        // Clearing: -10,000 (deposit) + 4,000 (withdrawal) - 4,000 (reversal).
        $this->assertSame(-10_000, (int) Wallet::query()->where('system_code', Wallet::SYSTEM_GATEWAY_CLEARING)->value('balance_minor'));

        // Repeating the refund returns the same reversal; no second credit.
        $this->assertTrue($refund->is($this->wallets->refund($withdrawal, 'Bank transfer failed')));
        $this->assertSame(10_000, $this->balance($wallet));

        // A reversal cannot be reversed.
        $this->assertRefused(WalletOperationException::INVALID_OPERATION, fn () => $this->wallets->refund($refund, 'again'));
    }

    public function test_reversing_a_credit_still_requires_funds(): void
    {
        $wallet = $this->player();
        $deposit = $this->wallets->credit($wallet, 5_000, LedgerTransactionType::Deposit, 'deposit:chargeback');
        $this->wallets->debit($wallet, 4_000, LedgerTransactionType::EntryFee, 'fee:spent');

        $this->assertRefused(WalletOperationException::INSUFFICIENT_FUNDS, fn () => $this->wallets->refund($deposit, 'Chargeback'));
        $this->assertSame(1_000, $this->balance($wallet));
        $this->assertSame('completed', $deposit->fresh()->status());
    }

    public function test_entry_fee_is_charged_from_the_frozen_seat_fee_and_refunded(): void
    {
        config(['competition.paid_entries.enabled' => true]);
        [$room, $seat, $wallet] = $this->paidSeat(fee: 5_000, balance: 12_000);

        $charge = $this->wallets->chargeEntryFee($seat);

        $this->assertSame(LedgerTransactionType::EntryFee, $charge->type);
        $this->assertSame(7_000, $this->balance($wallet));
        $this->assertSame($charge->id, $seat->fresh()->entry_ledger_transaction_id);
        $this->assertTrue($charge->related->is($seat));
        $this->assertSame(5_000, (int) Wallet::query()->where('system_code', Wallet::SYSTEM_PRIZE_POOL)->value('balance_minor'));

        // Retried: same charge, no second debit.
        $this->assertTrue($charge->is($this->wallets->chargeEntryFee($seat->fresh())));
        $this->assertSame(7_000, $this->balance($wallet));

        $refund = $this->wallets->refundEntryFee($seat, 'room cancelled');
        $this->assertSame(LedgerTransactionType::EntryRefund, $refund->type);
        $this->assertSame(12_000, $this->balance($wallet));
        $this->assertSame($refund->id, $seat->fresh()->refund_ledger_transaction_id);
        $this->assertTrue($refund->is($this->wallets->refundEntryFee($seat, 'room cancelled')));
        $this->assertSame(12_000, $this->balance($wallet));
        $this->assertSame(0, (int) Wallet::query()->where('system_code', Wallet::SYSTEM_PRIZE_POOL)->value('balance_minor'));
    }

    public function test_entry_fee_is_refused_for_an_invalid_competition_operation(): void
    {
        [$room, $seat, $wallet] = $this->paidSeat(fee: 5_000, balance: 12_000);
        $count = LedgerTransaction::query()->count();

        // Paid entries are disabled by default.
        $this->assertRefused(WalletOperationException::DISABLED, fn () => $this->wallets->chargeEntryFee($seat));

        config(['competition.paid_entries.enabled' => true]);

        $room->forceFill(['status' => RoomStatus::InProgress])->save();
        $this->assertRefused(WalletOperationException::INVALID_OPERATION, fn () => $this->wallets->chargeEntryFee($seat));

        $room->forceFill(['status' => RoomStatus::Open])->save();
        $seat->forceFill(['status' => ParticipantStatus::Withdrawn])->save();
        $this->assertRefused(WalletOperationException::INVALID_OPERATION, fn () => $this->wallets->chargeEntryFee($seat));

        $free = RoomParticipant::factory()->create(['room_id' => Room::factory()->open()->create()->id, 'user_id' => $wallet->user_id, 'status' => ParticipantStatus::Reserved]);
        $this->assertRefused(WalletOperationException::INVALID_OPERATION, fn () => $this->wallets->chargeEntryFee($free));

        $this->assertSame(12_000, $this->balance($wallet));
        $this->assertSame($count, LedgerTransaction::query()->count());
        $this->assertNull($this->wallets->refundEntryFee($seat, 'nothing charged'));
    }

    public function test_entry_fee_with_insufficient_funds_leaves_the_seat_unpaid(): void
    {
        config(['competition.paid_entries.enabled' => true]);
        [, $seat, $wallet] = $this->paidSeat(fee: 5_000, balance: 4_999);

        $this->assertRefused(WalletOperationException::INSUFFICIENT_FUNDS, fn () => $this->wallets->chargeEntryFee($seat));

        $this->assertNull($seat->fresh()->entry_ledger_transaction_id);
        $this->assertSame(4_999, $this->balance($wallet));
    }

    public function test_a_failure_inside_the_operation_rolls_everything_back(): void
    {
        $wallet = $this->player(10_000);
        $prizePool = $this->wallets->systemWallet(Wallet::SYSTEM_PRIZE_POOL, 'PKR');
        [$tx, $entries] = [LedgerTransaction::query()->count(), LedgerEntry::query()->count()];

        try {
            app(LedgerService::class)->post(
                new LedgerPosting(LedgerTransactionType::EntryFee, 'fee:rollback', [$wallet->id => -2_000, $prizePool->id => 2_000], 'PKR'),
                fn () => throw new RuntimeException('Seat update failed'),
            );
            $this->fail('Expected the failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Seat update failed', $e->getMessage());
        }

        $this->assertSame(10_000, $this->balance($wallet));
        $this->assertSame(0, (int) $prizePool->fresh()->balance_minor);
        $this->assertNothingWritten($tx, $entries);

        // The key was not consumed: the operation can be retried successfully.
        $this->wallets->debit($wallet, 2_000, LedgerTransactionType::EntryFee, 'fee:rollback');
        $this->assertSame(8_000, $this->balance($wallet));
    }

    public function test_the_ledger_and_cached_balances_stay_consistent(): void
    {
        $a = $this->player(50_000);
        $b = $this->player(20_000);

        $fee = $this->wallets->debit($a, 7_500, LedgerTransactionType::EntryFee, 'c:1');
        $this->wallets->credit($b, 12_345, LedgerTransactionType::PrizePayout, 'c:2');
        $this->wallets->refund($fee, 'cancelled');
        $this->wallets->debit($b, 32_345, LedgerTransactionType::Withdrawal, 'c:3');
        try {
            $this->wallets->debit($b, 1, LedgerTransactionType::Withdrawal, 'c:4');
        } catch (WalletOperationException) {
        }

        foreach ([$a, $b] as $wallet) {
            $this->assertSame((int) LedgerEntry::query()->where('wallet_id', $wallet->id)->sum('amount_minor'), $this->balance($wallet));
        }
        $this->assertSame(0, (int) LedgerEntry::query()->sum('amount_minor'), 'Money is neither created nor destroyed.');
        $this->assertSame([], app(WalletReconciler::class)->check());
        $this->artisan('wallets:reconcile')->assertSuccessful();

        // A balance changed outside the ledger is detected (and not "fixed").
        DB::table('wallets')->where('id', $a->id)->update(['balance_minor' => 999_999]);
        $issues = app(WalletReconciler::class)->check();
        $this->assertSame('balance_matches_entries', $issues[0]['check']);
        $this->artisan('wallets:reconcile')->assertFailed();
        $this->assertSame(999_999, (int) DB::table('wallets')->where('id', $a->id)->value('balance_minor'));
    }

    public function test_secrets_are_rejected_from_ledger_metadata(): void
    {
        $wallet = $this->player();

        foreach (['card_number', 'api_key', 'gateway_secret', 'otp', 'nested'] as $key) {
            $metadata = $key === 'nested' ? ['gateway' => ['auth_token' => 'x']] : [$key => 'x'];

            try {
                $this->wallets->credit($wallet, 100, LedgerTransactionType::Deposit, 'meta:'.$key, metadata: $metadata);
                $this->fail("{$key} must be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->wallets->credit($wallet, 100, LedgerTransactionType::Deposit, 'meta:ok', metadata: ['shipping_note' => 'ok', 'room_id' => 4]);
        $this->assertSame(100, $this->balance($wallet));
    }

    public function test_wallet_operations_broadcast_nothing(): void
    {
        $broadcast = [];
        Event::listen('*', function (string $name, array $payload) use (&$broadcast) {
            foreach ($payload as $event) {
                if ($event instanceof ShouldBroadcast) {
                    $broadcast[] = $name;
                }
            }
        });

        $wallet = $this->player(10_000);
        $tx = $this->wallets->debit($wallet, 1_000, LedgerTransactionType::EntryFee, 'b:1');
        $this->wallets->refund($tx, 'test');

        $this->assertSame([], $broadcast, 'Finance data must never be sent over WebSockets.');
    }

    /**
     * @return array{Room, RoomParticipant, Wallet}
     */
    protected function paidSeat(int $fee, int $balance): array
    {
        $wallet = $this->player($balance);
        $room = Room::factory()->open()->withEntryFee($fee)->create();
        $seat = RoomParticipant::factory()->create([
            'room_id' => $room->id, 'user_id' => $wallet->user_id, 'status' => ParticipantStatus::Reserved,
            'entry_fee_minor' => $fee, 'reserved_until' => now()->addMinutes(10),
        ]);

        return [$room, $seat, $wallet];
    }

    protected function assertRefused(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected the operation to be refused ({$reason}).");
        } catch (WalletOperationException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }
}
