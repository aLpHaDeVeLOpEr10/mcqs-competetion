<?php

namespace App\Console\Commands;

use App\Services\Reports\FinanceReconciliation;
use App\Services\Reports\ReportPeriod;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Operator / monitoring version of Admin → Reports → Reconciliation (read-only):
 *  - player wallets: opening + movements = closing for the period;
 *  - business records vs ledger: deposits, withdrawal holds, prize credits, entry refunds;
 *  - the all-time ledger check (balances vs entries, balanced transactions).
 * Exits non-zero on any difference. Never changes data; differences are investigated by
 * finance staff and corrected only through audited adjustments.
 */
class FinanceReconcile extends Command
{
    protected $signature = 'finance:reconcile
        {--period=30d : today | 7d | 30d | custom}
        {--from= : First day (Y-m-d, Pakistan time) for --period=custom}
        {--to= : Last day (Y-m-d) for --period=custom}';

    protected $description = 'Reconcile payments, withdrawals, prizes and refunds against the ledger (read-only)';

    public function handle(FinanceReconciliation $reconciliation): int
    {
        try {
            $period = ReportPeriod::fromArray(array_filter([
                'period' => $this->option('period'), 'from' => $this->option('from'), 'to' => $this->option('to'),
            ]));
        } catch (ValidationException $e) {
            $this->error(implode(' ', $e->validator->errors()->all()));

            return self::INVALID;
        }

        $r = $reconciliation->for($period);
        $this->info('Period: '.$period->label());

        $this->table(['Player wallets', 'Amount'], [
            ['Opening balance', Money::format($r['opening'])],
            ['Movements', Money::format($r['movements_total'])],
            ['Closing balance', Money::format($r['closing'])],
            ['Opening + movements = closing', $r['balanced'] ? 'yes' : 'NO'],
        ]);

        $this->table(['Check', 'Records', 'Ledger', 'Difference'], array_map(fn ($c) => [
            $c['label'], Money::format($c['records']), Money::format($c['ledger']), $c['difference'] === 0 ? '0' : Money::format($c['difference']).'  <-- investigate',
        ], $r['checks']));

        $this->line('All-time ledger issues (wallets:reconcile): '.$r['ledger_issues']);

        $differences = count(array_filter($r['checks'], fn ($c) => $c['difference'] !== 0));
        if (! $r['balanced'] || $differences > 0 || $r['ledger_issues'] > 0) {
            $this->error('Reconciliation found differences. Nothing was changed; investigate before any correction.');

            return self::FAILURE;
        }

        $this->info('Reconciled: records and ledger agree.');

        return self::SUCCESS;
    }
}
