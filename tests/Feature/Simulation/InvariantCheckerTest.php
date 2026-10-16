<?php

namespace Tests\Feature\Simulation;

use App\Enums\LedgerTransactionType;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\AssertsFinancialInvariants;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

/**
 * The invariant checker is only useful if it fails on bad data: each case below
 * corrupts the stored records the way a bug would, and must be caught.
 */
class InvariantCheckerTest extends TestCase
{
    use AssertsFinancialInvariants, CreatesRooms, RefreshDatabase;

    protected User $player;

    protected int $seatId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['competition.paid_entries.enabled' => true]);
        $wallets = app(WalletService::class);
        $this->player = User::factory()->create();
        $wallets->credit($wallets->walletFor($this->player), 20_000, LedgerTransactionType::Deposit, 'seed');
        $room = $this->openRoom(['entry_fee_minor' => 5_000, 'min_participants' => 2, 'max_participants' => 5]);
        app(RoomEntryService::class)->join($this->player, $room);
        $this->seatId = DB::table('room_participants')->value('id');
    }

    protected function assertCaught(string $why): void
    {
        try {
            $this->assertFinancialInvariants();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("The invariant checker missed: {$why}");
    }

    public function test_clean_data_passes(): void
    {
        $this->assertFinancialInvariants();
    }

    public function test_a_balance_changed_outside_the_ledger_is_caught(): void
    {
        DB::table('wallets')->whereNotNull('user_id')->update(['balance_minor' => 999_999]);
        $this->assertCaught('materialized balance ≠ ledger');
    }

    public function test_a_seat_charged_the_wrong_amount_is_caught(): void
    {
        DB::table('room_participants')->where('id', $this->seatId)->update(['entry_fee_minor' => 4_000]);
        $this->assertCaught('charge ≠ fee');
    }

    public function test_a_seat_pointing_at_a_non_entry_fee_transaction_is_caught(): void
    {
        $deposit = DB::table('ledger_transactions')->where('type', LedgerTransactionType::Deposit->value)->value('id');
        DB::table('room_participants')->where('id', $this->seatId)->update(['entry_ledger_transaction_id' => $deposit]);
        $this->assertCaught('seat linked to a deposit');
    }

    public function test_an_entry_fee_without_a_seat_is_caught(): void
    {
        DB::table('room_participants')->where('id', $this->seatId)->update(['entry_ledger_transaction_id' => null]);
        $this->assertCaught('debit without a seat');
    }

    public function test_an_unbalanced_ledger_transaction_is_caught(): void
    {
        $tx = DB::table('ledger_transactions')->insertGetId(['reference' => 'TESTUNBALANCED00000000000', 'idempotency_key' => 'bad', 'type' => 'adjustment', 'currency' => 'PKR', 'description' => 'bad', 'created_at' => now()]);
        $wallet = DB::table('wallets')->whereNotNull('user_id')->value('id');
        DB::table('ledger_entries')->insert(['ledger_transaction_id' => $tx, 'wallet_id' => $wallet, 'amount_minor' => 100, 'balance_after_minor' => 15_100, 'created_at' => now()]);
        $this->assertCaught('single-sided entry');
    }
}
