<?php

namespace App\Services\Engine;

use App\Events\Competition\CompetitionEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends live events after the engine's transaction commits. A WebSocket
 * outage must never block or roll back a competition: failures are logged
 * and clients recover by polling the authoritative state endpoint.
 */
class EngineBroadcaster
{
    /**
     * @param  list<CompetitionEvent>  $events
     */
    public function send(array $events): void
    {
        foreach ($events as $event) {
            try {
                event($event);
            } catch (Throwable $e) {
                Log::warning('Live competition broadcast failed; clients will fall back to polling.', [
                    'event' => $event::class,
                    'session_id' => $event->sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
