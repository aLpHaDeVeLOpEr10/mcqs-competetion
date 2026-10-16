<?php

namespace App\Services\Reports;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PayoutStatus;
use App\Enums\WalletType;
use App\Models\Wallet;
use App\Services\Wallet\WalletReconciler;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation for a period, all from SQL aggregates:
 *  - player wallets: opening balance + movements by type = closing balance;
 *  - cross-checks between the ledger and the business records that caused each
 *    movement (payments, withdrawals, prize payouts, refunded seats). A non-zero
 *    difference means a record and its money disagree and must be investigated;
 *  - platform account balances and the all-time ledger reconciliation.
 */
class FinanceReconciliation
{
    public function __construct(protected ReportMetrics $metrics, protected WalletReconciler $reconciler) {}

    /**
     * @return array<string, mixed>
     */
    public function for(ReportPeriod $p): array
    {
        $balanceBefore = fn ($moment) => (int) $this->metrics->userLedger()->where('t.created_at', '<', $moment)->sum('e.amount_minor');
        $opening = $balanceBefore($p->from);
        $closing = $balanceBefore($p->to);

        $movements = $p->apply($this->metrics->userLedger(), 't.created_at')
            ->groupBy('t.type')->selectRaw('t.type as type, SUM(e.amount_minor) as total')->pluck('total', 'type')
            ->map(fn ($v) => (int) $v)->all();

        $ledger = fn (LedgerTransactionType $type) => $this->metrics->userMovement($p, $type);
        $sum = fn ($query, string|Expression $column) => (int) $query->sum($column);

        $checks = [
            [
                'label' => 'Deposits: successful payments vs ledger deposit credits',
                'records' => $sum($p->apply(DB::table('payment_transactions')->where('type', PaymentType::Deposit->value)->where('status', PaymentStatus::Succeeded->value), 'completed_at'), 'amount_minor'),
                'ledger' => $ledger(LedgerTransactionType::Deposit),
            ],
            [
                'label' => 'Withdrawals: amount + fee requested vs ledger holds',
                'records' => $sum($p->apply(DB::table('withdrawals')->whereNotNull('hold_ledger_transaction_id'), 'created_at'), DB::raw('amount_minor + fee_minor')),
                'ledger' => -$ledger(LedgerTransactionType::Withdrawal),
            ],
            [
                'label' => 'Prizes: paid payouts (net) vs ledger prize credits',
                'records' => $sum($p->apply(DB::table('prize_payouts')->whereIn('status', [PayoutStatus::Paid->value, PayoutStatus::Reversed->value]), 'paid_at'), 'net_amount_minor'),
                'ledger' => $ledger(LedgerTransactionType::PrizePayout),
            ],
            [
                'label' => 'Entry fees: refunded seats vs ledger entry refunds',
                'records' => $sum($p->apply(DB::table('room_participants')->whereNotNull('refund_ledger_transaction_id'), 'refunded_at'), 'entry_fee_minor'),
                'ledger' => $ledger(LedgerTransactionType::EntryRefund),
            ],
        ];
        foreach ($checks as &$check) {
            $check['difference'] = $check['ledger'] - $check['records'];
        }

        return [
            'opening' => $opening,
            'movements' => $movements,
            'movements_total' => array_sum($movements),
            'closing' => $closing,
            'balanced' => $opening + array_sum($movements) === $closing,
            'checks' => $checks,
            'system_wallets' => Wallet::query()->where('type', WalletType::System)->orderBy('system_code')->pluck('balance_minor', 'system_code')->map(fn ($v) => (int) $v)->all(),
            'ledger_issues' => count($this->reconciler->check()),
        ];
    }
}
