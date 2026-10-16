<?php

namespace App\Services\Wallet;

use Illuminate\Support\Facades\DB;

/**
 * Checks the cached balances against the ledger, which is the source of
 * truth. It only reports; it never changes balances. A mismatch means
 * something wrote money outside LedgerService and needs investigating.
 */
class WalletReconciler
{
    /**
     * @return list<array{check: string, subject: string, expected: int|string, actual: int|string}>
     */
    public function check(?int $walletId = null): array
    {
        $issues = [];

        // 1. Cached balance = sum of the wallet's entries = balance after its latest entry.
        $wallets = DB::table('wallets')
            ->when($walletId, fn ($q) => $q->where('wallets.id', $walletId))
            ->leftJoinSub(
                DB::table('ledger_entries')->selectRaw('wallet_id, SUM(amount_minor) AS total, MAX(id) AS last_id')->groupBy('wallet_id'),
                'sums', 'sums.wallet_id', '=', 'wallets.id')
            ->leftJoin('ledger_entries as last', 'last.id', '=', 'sums.last_id')
            ->get(['wallets.id', 'wallets.balance_minor', 'sums.total', 'last.balance_after_minor']);

        foreach ($wallets as $wallet) {
            $sum = (int) ($wallet->total ?? 0);

            if ((int) $wallet->balance_minor !== $sum) {
                $issues[] = ['check' => 'balance_matches_entries', 'subject' => 'wallet #'.$wallet->id, 'expected' => $sum, 'actual' => (int) $wallet->balance_minor];
            }

            if ($wallet->balance_after_minor !== null && (int) $wallet->balance_after_minor !== $sum) {
                $issues[] = ['check' => 'running_balance', 'subject' => 'wallet #'.$wallet->id, 'expected' => $sum, 'actual' => (int) $wallet->balance_after_minor];
            }
        }

        // 2. Every transaction is balanced and has at least two entries.
        $unbalanced = DB::table('ledger_entries')
            ->when($walletId, fn ($q) => $q->whereIn('ledger_transaction_id', DB::table('ledger_entries')->select('ledger_transaction_id')->where('wallet_id', $walletId)))
            ->selectRaw('ledger_transaction_id, SUM(amount_minor) AS net, COUNT(*) AS legs')
            ->groupBy('ledger_transaction_id')
            ->havingRaw('SUM(amount_minor) <> 0 OR COUNT(*) < 2')
            ->get();

        foreach ($unbalanced as $row) {
            $issues[] = ['check' => 'transaction_balanced', 'subject' => 'ledger transaction #'.$row->ledger_transaction_id, 'expected' => '0 net, ≥ 2 entries', 'actual' => $row->net.' net, '.$row->legs.' entries'];
        }

        // 3. No transaction without entries.
        if ($walletId === null) {
            DB::table('ledger_transactions')->whereNotExists(fn ($q) => $q->from('ledger_entries')->whereColumn('ledger_entries.ledger_transaction_id', 'ledger_transactions.id'))
                ->pluck('id')->each(function ($id) use (&$issues) {
                    $issues[] = ['check' => 'transaction_has_entries', 'subject' => 'ledger transaction #'.$id, 'expected' => '≥ 2 entries', 'actual' => '0 entries'];
                });
        }

        return $issues;
    }
}
