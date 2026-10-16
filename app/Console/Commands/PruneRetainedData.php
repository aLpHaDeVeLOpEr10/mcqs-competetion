<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention for short-lived data only (see docs/REPORTS.md). Audit logs, the
 * ledger, payments, withdrawals, results and callbacks are never pruned here.
 */
class PruneRetainedData extends Command
{
    protected $signature = 'retention:prune';

    protected $description = 'Delete expired CSV exports and old read notifications';

    public function handle(): int
    {
        $exports = 0;
        ReportExport::query()->where('created_at', '<', now()->subDays(ReportExport::EXPIRES_DAYS))->each(function (ReportExport $export) use (&$exports) {
            if ($export->path) {
                Storage::disk('local')->delete($export->path);
            }
            $export->delete();
            $exports++;
        });

        $days = (int) config('competition.retention.read_notifications_days', 180);
        $notifications = DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$exports} expired export(s) and {$notifications} read notification(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
