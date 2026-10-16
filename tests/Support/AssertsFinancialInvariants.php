<?php

namespace Tests\Support;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Services\Wallet\WalletReconciler;
use Illuminate\Support\Facades\DB;

/**
 * Whole-database money invariants, checked after a scenario. Each assertion reads
 * the stored records directly (not the services), so it catches any path that
 * bypassed the ledger.
 */
trait AssertsFinancialInvariants
{
    protected function assertFinancialInvariants(): void
    {
        // 1. Double entry: every ledger transaction has ≥ 2 entries summing to zero.
        $unbalanced = DB::table('ledger_entries')->select('ledger_transaction_id')
            ->groupBy('ledger_transaction_id')->havingRaw('SUM(amount_minor) <> 0 OR COUNT(*) < 2')->count();
        $this->assertSame(0, $unbalanced, 'Every ledger transaction balances.');
        $this->assertSame(0, DB::table('ledger_transactions as t')->whereNotExists(fn ($q) => $q->from('ledger_entries as e')->whereColumn('e.ledger_transaction_id', 't.id'))->count(), 'No ledger transaction without entries.');

        // 2. Materialized balances equal the ledger; the running balance of the latest entry agrees.
        $this->assertSame([], app(WalletReconciler::class)->check(), 'Wallet balances match the ledger.');

        // 3. No negative spendable balance on any player wallet (not supported by this platform).
        $this->assertSame(0, DB::table('wallets')->whereNotNull('user_id')->where('balance_minor', '<', 0)->count(), 'No player wallet is negative.');

        // 4. Idempotency references and public references are unique; a transaction is reversed at most once.
        foreach (['idempotency_key', 'reference'] as $column) {
            $this->assertSame(0, DB::table('ledger_transactions')->whereNotNull($column)->select($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->count(), "Unique ledger {$column}.");
        }
        $this->assertSame(0, DB::table('ledger_transactions')->whereNotNull('reverses_transaction_id')->select('reverses_transaction_id')->groupBy('reverses_transaction_id')->havingRaw('COUNT(*) > 1')->count(), 'Reversed at most once.');

        // 5. Entry fees: every charged seat points at an entry-fee debit of exactly its fee from the player's wallet,
        //    and every entry-fee transaction belongs to exactly one seat.
        $charged = DB::table('room_participants as p')->join('ledger_transactions as t', 't.id', '=', 'p.entry_ledger_transaction_id')
            ->join('wallets as w', 'w.user_id', '=', 'p.user_id')
            ->join('ledger_entries as e', fn ($j) => $j->on('e.ledger_transaction_id', '=', 't.id')->on('e.wallet_id', '=', 'w.id'))
            ->select('p.id', 'p.entry_fee_minor', 't.type', 'e.amount_minor')->get();
        foreach ($charged as $seat) {
            $this->assertSame(LedgerTransactionType::EntryFee->value, $seat->type, "Seat #{$seat->id} charge is an entry fee.");
            $this->assertSame(-(int) $seat->entry_fee_minor, (int) $seat->amount_minor, "Seat #{$seat->id} was charged exactly its fee.");
        }
        $this->assertSame(DB::table('room_participants')->whereNotNull('entry_ledger_transaction_id')->count(), $charged->count(), 'Every recorded charge exists in the ledger.');
        $this->assertSame(
            DB::table('ledger_transactions')->where('type', LedgerTransactionType::EntryFee->value)->count(),
            DB::table('room_participants')->whereNotNull('entry_ledger_transaction_id')->distinct()->count('entry_ledger_transaction_id'),
            'No entry-fee debit without a seat.',
        );

        // 6. Refunds never exceed the original: each refund reverses exactly the charge it refers to.
        $refunds = DB::table('room_participants')->whereNotNull('refund_ledger_transaction_id')->get(['id', 'entry_ledger_transaction_id', 'refund_ledger_transaction_id']);
        foreach ($refunds as $seat) {
            $charge = (int) DB::table('ledger_entries')->where('ledger_transaction_id', $seat->entry_ledger_transaction_id)->where('amount_minor', '<', 0)->sum('amount_minor');
            $refund = (int) DB::table('ledger_entries')->where('ledger_transaction_id', $seat->refund_ledger_transaction_id)->where('amount_minor', '>', 0)->sum('amount_minor');
            $this->assertSame(-$charge, $refund, "Seat #{$seat->id} refund equals its charge.");
            $this->assertSame((int) $seat->entry_ledger_transaction_id, (int) DB::table('ledger_transactions')->where('id', $seat->refund_ledger_transaction_id)->value('reverses_transaction_id'));
        }

        // 7. Deposits: each succeeded payment has exactly one deposit credit of its amount; nothing else credits deposits.
        $succeeded = DB::table('payment_transactions')->where('status', PaymentStatus::Succeeded->value)->get(['id', 'amount_minor', 'wallet_id', 'ledger_transaction_id']);
        foreach ($succeeded as $payment) {
            $this->assertNotNull($payment->ledger_transaction_id, "Payment #{$payment->id} has a ledger record.");
            $this->assertSame((int) $payment->amount_minor, (int) DB::table('ledger_entries')->where('ledger_transaction_id', $payment->ledger_transaction_id)->where('wallet_id', $payment->wallet_id)->value('amount_minor'));
        }
        $this->assertSame(0, DB::table('payment_transactions')->where('status', '<>', PaymentStatus::Succeeded->value)->whereNotNull('ledger_transaction_id')->count(), 'Only succeeded payments credit.');

        // 8. Prizes match the configuration frozen at the start, and paid prizes match their ledger credit.
        foreach (DB::table('prize_distributions')->get() as $distribution) {
            $frozen = json_decode((string) DB::table('competition_sessions')->where('id', $distribution->competition_session_id)->value('config'), true);
            $calculation = json_decode((string) $distribution->calculation, true);
            $this->assertEquals($frozen['prize_rules'] ?? null, $calculation['rules'] ?? null, "Distribution #{$distribution->id} uses the frozen prize rules.");

            $payouts = DB::table('prize_payouts')->where('prize_distribution_id', $distribution->id)->where('status', '<>', PayoutStatus::Cancelled->value)->get();
            $this->assertSame((int) $distribution->total_amount_minor, (int) $payouts->sum('amount_minor'), 'Payouts add up to the awarded total.');
            $this->assertSame((int) $distribution->prize_pool_minor, (int) $distribution->total_amount_minor + (int) $distribution->unallocated_minor, 'Awarded + unallocated = pool.');

            foreach ($payouts->groupBy(fn ($p) => json_encode(json_decode($p->rule, true)['positions'])) as $group) {
                $rule = json_decode($group->first()->rule, true);
                $this->assertSame(array_sum($rule['position_amounts_minor']), (int) $group->sum('amount_minor') + (int) $rule['group_remainder_minor'], 'Tied places are split exactly.');
            }

            foreach ($payouts->where('status', PayoutStatus::Paid->value) as $payout) {
                $this->assertSame((int) $payout->amount_minor - (int) $payout->tax_withheld_minor, (int) $payout->net_amount_minor);
                $credit = (int) DB::table('ledger_entries as e')->join('wallets as w', 'w.id', '=', 'e.wallet_id')
                    ->where('e.ledger_transaction_id', $payout->ledger_transaction_id)->whereNotNull('w.user_id')->sum('e.amount_minor');
                $this->assertSame((int) $payout->net_amount_minor, $credit, "Payout #{$payout->id} credited exactly once, net.");
            }
        }
        $this->assertSame(
            DB::table('ledger_transactions')->where('type', LedgerTransactionType::PrizePayout->value)->count(),
            DB::table('prize_payouts')->whereNotNull('ledger_transaction_id')->count(),
            'No prize credit without a payout record.',
        );
    }
}
