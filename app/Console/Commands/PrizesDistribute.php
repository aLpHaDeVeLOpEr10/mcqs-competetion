<?php

namespace App\Console\Commands;

use App\Enums\PayoutStatus;
use App\Models\PrizePayout;
use App\Services\Prizes\PrizeDistributionService;
use Illuminate\Console\Command;

/**
 * Retries prize distribution for competitions with unpaid entitlements:
 * releases automatic holds whose cause has cleared (dispute resolved, review
 * approved, payouts enabled) and finishes payouts interrupted mid-way.
 * Idempotent: a prize is never credited twice.
 */
class PrizesDistribute extends Command
{
    protected $signature = 'prizes:distribute {--session= : Only this competition session id}';

    protected $description = 'Pay outstanding prize entitlements whose payout conditions are met';

    public function handle(PrizeDistributionService $prizes): int
    {
        $sessions = $this->option('session') !== null
            ? collect([(int) $this->option('session')])
            : PrizePayout::query()
                ->join('prize_distributions as d', 'd.id', '=', 'prize_payouts.prize_distribution_id')
                ->whereIn('prize_payouts.status', [PayoutStatus::Pending, PayoutStatus::Approved, PayoutStatus::Held, PayoutStatus::Processing])
                ->distinct()->orderBy('d.competition_session_id')->pluck('d.competition_session_id');

        foreach ($sessions as $sessionId) {
            $result = $prizes->distribute($sessionId);
            $this->line("Session {$sessionId}: {$result['status']}".($result['reason'] ? " ({$result['reason']})" : '')
                ." — {$result['paid']} paid, {$result['failed']} failed.");
        }

        $this->info($sessions->count().' competition(s) checked.');

        return self::SUCCESS;
    }
}
