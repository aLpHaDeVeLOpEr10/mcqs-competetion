<?php

namespace App\Jobs;

use App\Exceptions\AdministrativeActionException;
use App\Services\Prizes\PrizeDistributionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pays the prize entitlements of one finalized competition. Safe to retry:
 * every payout is credited under its own ledger idempotency key, so a retried
 * or duplicated run never pays twice. One job per session at a time.
 * prizes:distribute (every five minutes) is the recovery path.
 */
class DistributePrizes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    // Below the database queue retry_after (90 s), so a running job is never handed to a second worker.
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $sessionId)
    {
        $this->onQueue(config('competition.queues.default'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function uniqueId(): string
    {
        return (string) $this->sessionId;
    }

    public function handle(PrizeDistributionService $prizes): void
    {
        try {
            $prizes->distribute($this->sessionId);
        } catch (AdministrativeActionException $e) {
            // Not payable in its current state (e.g. payouts disabled, held for review): permanent.
            $this->fail($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Prize distribution job failed; prizes:distribute will retry eligible payouts.', [
            'session_id' => $this->sessionId,
            'error' => $e?->getMessage(),
        ]);
    }
}
