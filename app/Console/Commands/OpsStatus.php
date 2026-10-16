<?php

namespace App\Console\Commands;

use App\Enums\SessionStatus;
use App\Models\CompetitionSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Operational health in one place: is the scheduler alive, are workers keeping
 * up, have jobs failed, is anything overdue. Read-only; exits non-zero when
 * unhealthy so it can back an external monitor or a deployment check.
 */
class OpsStatus extends Command
{
    public const TICK_HEARTBEAT = 'ops:heartbeat:competitions-tick';

    protected $signature = 'ops:status {--live : Print only the number of competitions starting or in progress (for deploy scripts)}';

    protected $description = 'Show scheduler, queue and competition health (read-only)';

    public function handle(): int
    {
        if ($this->option('live')) {
            $this->line((string) CompetitionSession::query()->whereIn('status', [SessionStatus::Pending, SessionStatus::Countdown, SessionStatus::InProgress, SessionStatus::Scoring])
                ->whereHas('room', fn ($q) => $q->whereIn('status', ['starting', 'in_progress']))->count());

            return self::SUCCESS;
        }

        $problems = 0;
        $rows = [];

        $beat = Cache::get(self::TICK_HEARTBEAT);
        $age = $beat ? (int) CarbonImmutable::parse($beat)->diffInSeconds(now()) : null;
        $tickOk = $age !== null && $age <= config('competition.queues.tick_stale_seconds');
        $rows[] = ['Scheduler (competitions:tick)', $age === null ? 'never ran' : "last ran {$age}s ago", $tickOk ? 'ok' : 'PROBLEM'];
        $problems += $tickOk ? 0 : 1;

        if (config('queue.default') === 'database') {
            $table = config('queue.connections.database.table', 'jobs');
            $now = now()->getTimestamp();
            foreach (DB::table($table)->selectRaw('queue, COUNT(*) as size, MIN(available_at) as oldest')->groupBy('queue')->get() as $queue) {
                $lag = max(0, $now - (int) $queue->oldest);
                $ok = $queue->size <= config('competition.queues.monitor_max') && $lag <= 60;
                $rows[] = ["Queue \"{$queue->queue}\"", "{$queue->size} waiting, oldest due {$lag}s ago", $ok ? 'ok' : 'PROBLEM'];
                $problems += $ok ? 0 : 1;
            }
        }

        $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
        $rows[] = ['Failed jobs', (string) $failed, $failed === 0 ? 'ok' : 'check'];

        $live = CompetitionSession::query()->whereIn('status', [SessionStatus::Countdown, SessionStatus::InProgress])->count();
        $scoring = CompetitionSession::query()->where('status', SessionStatus::Scoring)->where('ended_at', '<', now()->subMinute())->count();
        $rows[] = ['Live competitions', (string) $live, 'ok'];
        $rows[] = ['Awaiting results > 1 min', (string) $scoring, $scoring === 0 ? 'ok' : 'PROBLEM'];
        $problems += $scoring === 0 ? 0 : 1;

        // Payments: provider notifications that failed verification recently (forgery, misconfiguration).
        $rejected = DB::table('payment_callbacks')->where('outcome', 'rejected')->where('created_at', '>=', now()->subHour())->count();
        $rows[] = ['Rejected payment callbacks (last hour)', (string) $rejected, $rejected < 10 ? ($rejected === 0 ? 'ok' : 'check') : 'PROBLEM'];
        $problems += $rejected < 10 ? 0 : 1;

        // Risk review: high-severity flags (wallet discrepancy, provider reference conflict) waiting for staff.
        $highRisk = DB::table('risk_flags')->where('status', 'open')->where('severity', 'high')->count();
        $rows[] = ['Open high-severity risk flags', (string) $highRisk, $highRisk === 0 ? 'ok' : 'PROBLEM'];
        $problems += $highRisk === 0 ? 0 : 1;

        $this->table(['Check', 'Value', 'Status'], $rows);

        if ($problems > 0) {
            $this->error("{$problems} problem(s). See docs/OPERATIONS.md (runbook).");

            return self::FAILURE;
        }

        $this->info('Healthy.');

        return self::SUCCESS;
    }
}
