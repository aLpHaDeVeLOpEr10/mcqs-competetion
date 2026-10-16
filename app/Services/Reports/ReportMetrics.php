<?php

namespace App\Services\Reports;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayoutStatus;
use App\Enums\SessionStatus;
use App\Enums\WalletType;
use App\Enums\WithdrawalStatus;
use App\Models\Wallet;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Period figures for the reports dashboard. Every number is one SQL aggregate
 * over indexed columns; nothing loads rows into memory. Money is in minor
 * units and comes from the ledger where the ledger is the source of truth.
 * None of these figures is "profit".
 */
class ReportMetrics
{
    /**
     * @return array<string, int>
     */
    public function activity(ReportPeriod $p): array
    {
        $seats = $p->apply(DB::table('room_participants')->select('user_id'), 'confirmed_at');
        $moneyMovers = $p->apply($this->userLedger()->select('w.user_id'), 't.created_at');

        return [
            'registered_users' => $p->apply(DB::table('users'), 'created_at')->count(),
            // Active = took a seat in a competition, or had money move in their wallet, in the period.
            'active_users' => DB::query()->fromSub($seats->union($moneyMovers), 'active')->count(),
            'rooms_created' => $p->apply(DB::table('rooms'), 'created_at')->count(),
            'competitions_completed' => $p->apply(DB::table('competition_sessions')->where('status', SessionStatus::Completed->value), 'scored_at')->count(),
            'competition_entries' => $p->apply(DB::table('room_participants'), 'confirmed_at')->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function finance(ReportPeriod $p): array
    {
        $gross = -$this->userMovement($p, LedgerTransactionType::EntryFee);
        $refunds = $this->userMovement($p, LedgerTransactionType::EntryRefund);

        return [
            'gross_entry_fees' => $gross,
            'entry_fee_refunds' => $refunds,
            'net_entry_fees' => $gross - $refunds,
            'prize_credits' => $this->userMovement($p, LedgerTransactionType::PrizePayout),
            'prizes_paid' => $p->apply(DB::table('prize_payouts')->where('status', PayoutStatus::Paid->value), 'paid_at')->count(),
            'deposits' => $this->userMovement($p, LedgerTransactionType::Deposit),
            'deposits_count' => $p->apply(DB::table('payment_transactions')->where('type', PaymentType::Deposit->value)->where('status', PaymentStatus::Succeeded->value), 'completed_at')->count(),
            'withdrawals_paid' => (int) $p->apply(DB::table('withdrawals')->where('status', WithdrawalStatus::Paid->value), 'paid_at')->sum('amount_minor'),
            'withdrawals_requested' => (int) $p->apply(DB::table('withdrawals'), 'created_at')->sum('amount_minor'),
            'failed_payments' => $p->apply(DB::table('payment_transactions')->whereIn('status', [PaymentStatus::Failed->value, PaymentStatus::Expired->value]), 'updated_at')->count(),
            'failed_withdrawals' => $p->apply(DB::table('withdrawals')->where('status', WithdrawalStatus::Failed->value), 'failed_at')->count(),
            'rejected_withdrawals' => $p->apply(DB::table('withdrawals')->where('status', WithdrawalStatus::Rejected->value), 'rejected_at')->count(),
            'adjustments' => $this->userMovement($p, LedgerTransactionType::Adjustment),
            // Only what the accounting model defines as platform income: the platform share of
            // entry fees (prize settings) and disclosed withdrawal fees, net of any refunds of them.
            'platform_revenue' => (int) $p->apply(
                DB::table('ledger_entries as e')->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')
                    ->join('wallets as w', 'w.id', '=', 'e.wallet_id')->where('w.system_code', Wallet::SYSTEM_PLATFORM_REVENUE),
                't.created_at')->sum('e.amount_minor'),
        ];
    }

    /**
     * Net movement on player wallets for one transaction type (credits positive).
     */
    public function userMovement(ReportPeriod $p, LedgerTransactionType $type): int
    {
        return (int) $p->apply($this->userLedger()->where('t.type', $type->value), 't.created_at')->sum('e.amount_minor');
    }

    /**
     * Ledger entries on player wallets.
     */
    public function userLedger(): Builder
    {
        return DB::table('ledger_entries as e')
            ->join('ledger_transactions as t', 't.id', '=', 'e.ledger_transaction_id')
            ->join('wallets as w', 'w.id', '=', 'e.wallet_id')
            ->where('w.type', WalletType::User->value);
    }
}
