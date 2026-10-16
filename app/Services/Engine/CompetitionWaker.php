<?php

namespace App\Services\Engine;

use App\Jobs\AdvanceCompetition;
use Carbon\CarbonInterface;

/**
 * Schedules a precise engine wake-up via a delayed queue job. The "sync"
 * queue cannot delay jobs (it would run the engine recursively), so no job
 * is dispatched there; the competitions:tick scheduler still advances
 * every session, which is also the recovery path when workers are down.
 *
 * Jobs are unique per (session, due moment): asking again for the same moment
 * (every tick does) does not queue another job.
 */
class CompetitionWaker
{
    public function wake(int $sessionId, ?CarbonInterface $at = null): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        $future = $at !== null && $at->isFuture();
        $job = AdvanceCompetition::dispatch($sessionId, $future ? $at->format('Y-m-d\TH:i:s.vP') : null);

        if ($future) {
            $job->delay($at);
        }
    }
}
