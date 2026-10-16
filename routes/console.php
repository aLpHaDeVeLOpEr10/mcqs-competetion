<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every entry is safe to run repeatedly. onOneServer() needs a shared cache store with
// atomic locks (database or redis) when several servers run the scheduler.
// Locally: `php artisan schedule:work`. Production: cron `* * * * * php artisan schedule:run`.

// Recovery heartbeat for rooms (reservation expiry, opening, starts, cancellations)
// and live sessions (question opening/closing, finalization). Precise timing comes
// from delayed AdvanceCompetition jobs; this keeps everything moving if they fail.
Schedule::command('competitions:tick')->everyFiveSeconds()->withoutOverlapping(1)->onOneServer();

// Deeper reconciliation from persisted timestamps: overdue steps, unfinalized results,
// rooms lagging their session, unreturned entry fees; reports what needs an operator.
Schedule::command('competitions:recover')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// Report-only check that cached wallet balances match the immutable ledger.
Schedule::command('wallets:reconcile')->dailyAt('03:00')->withoutOverlapping()->onOneServer();

// Records vs ledger (deposits, withdrawal holds, prizes, refunds) for the last 7 days; report-only.
Schedule::command('finance:reconcile', ['--period' => '7d'])->dailyAt('03:15')->withoutOverlapping()->onOneServer()
    ->onFailure(fn () => Log::critical('finance:reconcile found differences between business records and the ledger. Investigate (Admin → Reports → Reconciliation).'));

// Verifies unfinished deposits with the provider and expires abandoned ones.
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Pays prize entitlements whose conditions are met (holds released, interrupted payouts finished).
Schedule::command('prizes:distribute')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Starting-soon reminders (each sent at most once per player and offset).
Schedule::command('notifications:reminders')->everyMinute()->withoutOverlapping()->onOneServer();

// Confirms payouts in progress (and recent returns) with the payout provider.
Schedule::command('withdrawals:reconcile')->everyTenMinutes()->withoutOverlapping()->onOneServer();

// Retention: expired CSV exports and old read notifications (never audit or financial records).
Schedule::command('retention:prune')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// Queue observability: warns (QueueBusy → log) when a queue backs up; failed jobs kept 30 days.
Schedule::command('queue:monitor', [implode(',', [
    'database:'.config('competition.queues.engine'),
    'database:'.config('competition.queues.default'),
    'database-long:'.config('competition.queues.long'),
]), '--max='.config('competition.queues.monitor_max')])->everyMinute()->onOneServer();
Schedule::command('queue:prune-failed', ['--hours=720'])->dailyAt('04:00')->onOneServer();
