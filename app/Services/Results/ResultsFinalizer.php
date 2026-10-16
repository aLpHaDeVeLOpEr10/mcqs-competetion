<?php

namespace App\Services\Results;

use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Events\Competition\CompetitionCompleted;
use App\Events\Competition\ResultsPublished;
use App\Jobs\DistributePrizes;
use App\Models\CompetitionSession;
use App\Models\ResultVersion;
use App\Models\Room;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\CompetitionCompleted as CompetitionCompletedNotice;
use App\Notifications\ResultsReady;
use App\Services\Audit\AuditLogger;
use App\Services\Competition\RoomLifecycle;
use App\Services\Engine\EngineBroadcaster;
use App\Services\Notifications\Notifier;
use App\Services\Prizes\PrizeDistributionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finalizes a competition whose live part has ended (session "scoring").
 * Idempotent and safe under concurrency: it locks the room and session rows,
 * and a session that is already "completed" returns its existing results,
 * so concurrent workers can never produce conflicting results. The session
 * and room become "completed" only in the same transaction that stores the
 * results; if calculation fails nothing is marked complete and the
 * competitions:tick heartbeat retries.
 */
class ResultsFinalizer
{
    public function __construct(
        protected ResultsCalculator $calculator,
        protected RoomLifecycle $lifecycle,
        protected AuditLogger $audit,
        protected EngineBroadcaster $broadcaster,
    ) {}

    public function finalize(int $sessionId, ?User $actor = null): ?ResultVersion
    {
        $published = false;

        // Read before the transaction so the first statement inside is the locking read
        // (REPEATABLE READ snapshot; see CompetitionEngine::advance()).
        $roomId = CompetitionSession::query()->whereKey($sessionId)->value('room_id');
        if ($roomId === null) {
            return null;
        }

        $version = DB::transaction(function () use ($roomId, $sessionId, $actor, &$published) {
            $room = Room::query()->lockForUpdate()->findOrFail($roomId);
            $session = CompetitionSession::query()->lockForUpdate()->findOrFail($sessionId);

            if ($session->status === SessionStatus::Completed) {
                return $session->resultVersions()->latest('version')->first();
            }

            if ($session->status !== SessionStatus::Scoring) {
                return null;
            }

            $version = $this->calculator->calculate($room, $session, 'Initial results', $actor);
            $published = (bool) config('competition.results.auto_publish');

            $session->forceFill([
                'status' => SessionStatus::Completed,
                'scored_at' => now(),
                'results_published_at' => $published ? now() : null,
                'lock_version' => $session->lock_version + 1,
            ])->save();

            if ($room->status === RoomStatus::InProgress) {
                $this->lifecycle->transition($room, RoomStatus::Completed, $actor, 'Results finalized', ['result_version' => $version->version]);
            }

            $this->audit->record('results.finalized', $session, null, [
                'version' => $version->version,
                'scoring_policy' => $version->scoring_policy,
                'tie_break_policy' => $version->tie_break_policy,
                'inputs_hash' => $version->inputs_hash,
                'published' => $published,
            ], actor: $actor);

            return $version;
        });

        if ($version !== null && $published) {
            $session = CompetitionSession::query()->findOrFail($sessionId);
            $this->broadcaster->send([new CompetitionCompleted($session), new ResultsPublished($session, $version->version)]);
            $this->notifyPlayers($roomId, new ResultsReady(Room::query()->findOrFail($roomId)));
            $this->payPrizes($sessionId);
        } elseif ($version !== null && $version->wasRecentlyCreated) {
            $this->notifyPlayers($roomId, new CompetitionCompletedNotice(Room::query()->findOrFail($roomId)));
        }

        return $version;
    }

    /**
     * Tell everyone who played (not withdrawn or expired seats).
     */
    protected function notifyPlayers(int $roomId, AppNotification $notification): void
    {
        // Queued and chunked (see Notifier::toRoom): finalization is never held up by a large room.
        app(Notifier::class)->toRoom($roomId, [ParticipantStatus::Finished, ParticipantStatus::Disqualified], $notification);
    }

    /**
     * Internal wallet prize credits follow published results when the platform
     * allows it. With a queue, a retrying DistributePrizes job pays them; without
     * one they are paid inline. A failure never undoes the results;
     * competitions:recover and prizes:distribute retry.
     */
    protected function payPrizes(int $sessionId): void
    {
        if (! config('competition.prizes.payouts_enabled')) {
            return;
        }

        if (config('queue.default') !== 'sync') {
            DistributePrizes::dispatch($sessionId)->afterCommit();

            return;
        }

        try {
            app(PrizeDistributionService::class)->distribute($sessionId);
        } catch (Throwable $e) {
            Log::error('Prize distribution failed after finalization; the scheduler will retry.', ['session_id' => $sessionId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Publish results that were finalized while auto-publish was off.
     */
    public function publish(int $sessionId, User $actor): void
    {
        $version = DB::transaction(function () use ($sessionId, $actor) {
            $session = CompetitionSession::query()->lockForUpdate()->findOrFail($sessionId);

            if ($session->status !== SessionStatus::Completed || $session->results_published_at !== null) {
                return null;
            }

            $session->forceFill(['results_published_at' => now()])->save();
            $this->audit->record('results.published', $session, null, ['published_at' => now()->format(DATE_ATOM)], actor: $actor);

            return $session->resultVersions()->latest('version')->value('version');
        });

        if ($version !== null) {
            $session = CompetitionSession::query()->findOrFail($sessionId);
            $this->broadcaster->send([new ResultsPublished($session, $version)]);
            $this->notifyPlayers($session->room_id, new ResultsReady(Room::query()->findOrFail($session->room_id)));
            $this->payPrizes($sessionId);
        }
    }
}
