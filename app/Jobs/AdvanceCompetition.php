<?php

namespace App\Jobs;

use App\Exceptions\AdministrativeActionException;
use App\Services\Engine\CompetitionEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A precise wake-up for one competition session, dispatched with a delay for
 * the next due moment. Safe to run any number of times, late, or after a
 * worker restart: the engine only performs steps that are actually due, under
 * row locks. If no worker is running, competitions:tick does the same work.
 *
 * Unique per (session, due moment) until it starts processing, so the tick and
 * the engine scheduling the same moment queue ONE job, not one per tick.
 */
class AdvanceCompetition implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Transient failures (deadlock, lost connection) are retried quickly. */
    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /**
     * @param  string|null  $dueAt  the moment this wake-up is for (ISO-8601, ms), null = now
     */
    public function __construct(public readonly int $sessionId, public readonly ?string $dueAt = null)
    {
        $this->onQueue(config('competition.queues.engine'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 2, 5, 10];
    }

    public function uniqueId(): string
    {
        return $this->sessionId.'@'.($this->dueAt ?? 'now');
    }

    /**
     * The uniqueness lock outlives the delay by a minute; if the job is ever lost the
     * lock simply expires (and the tick has advanced the session meanwhile).
     */
    public function uniqueFor(): int
    {
        $delay = $this->dueAt === null ? 0 : max(0, now()->diffInSeconds($this->dueAt, false));

        return (int) $delay + 60;
    }

    public function handle(CompetitionEngine $engine): void
    {
        try {
            $engine->advance($this->sessionId);
        } catch (AdministrativeActionException $e) {
            // An impossible state transition is permanent: retrying cannot fix it.
            $this->fail($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('AdvanceCompetition failed; competitions:tick and competitions:recover will keep the session moving.', [
            'session_id' => $this->sessionId,
            'due_at' => $this->dueAt,
            'error' => $e?->getMessage(),
        ]);
    }
}
