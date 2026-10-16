<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Services\Security\RiskMonitor;
use App\Services\Wallet\WalletReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Report-only ledger reconciliation. Exits non-zero (and logs a critical
 * error) when a cached balance or transaction disagrees with the ledger.
 */
class WalletsReconcile extends Command
{
    protected $signature = 'wallets:reconcile {--wallet= : Check one wallet id only}';

    protected $description = 'Check wallet balances against the immutable ledger (never changes data)';

    public function handle(WalletReconciler $reconciler, RiskMonitor $risk): int
    {
        $walletId = $this->option('wallet') !== null ? (int) $this->option('wallet') : null;
        $issues = $reconciler->check($walletId);

        if ($issues === []) {
            $this->info('Ledger reconciled: every balance matches its entries and every transaction balances.');

            return self::SUCCESS;
        }

        $this->table(['Check', 'Subject', 'Expected', 'Actual'], $issues);
        Log::critical('Wallet reconciliation found discrepancies.', ['issues' => $issues]);
        $this->flag($risk, $issues);
        $this->error(count($issues).' discrepancy(ies) found. Balances were NOT changed; investigate before any correction.');

        return self::FAILURE;
    }

    /**
     * Opens a high-severity review flag per affected wallet (attributed to its owner for
     * player wallets). Nothing is corrected automatically.
     *
     * @param  list<array<string, mixed>>  $issues
     */
    protected function flag(RiskMonitor $risk, array $issues): void
    {
        foreach ($issues as $issue) {
            if (preg_match('/^wallet #(\d+)$/', (string) $issue['subject'], $m) !== 1) {
                continue;
            }
            $wallet = Wallet::query()->with('user')->find((int) $m[1]);
            if ($wallet !== null) {
                $risk->signal('wallet_discrepancy', $wallet->user, $wallet, ['check' => $issue['check'], 'expected' => $issue['expected'], 'actual' => $issue['actual']]);
            }
        }
    }
}
