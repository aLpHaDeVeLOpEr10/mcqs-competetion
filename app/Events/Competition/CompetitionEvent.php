<?php

namespace App\Events\Competition;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for live competition events. Broadcast immediately (not queued) on
 * the session's private channel, which only seated participants may join.
 * Payloads carry the minimum needed by the player screen and never include
 * answer keys, other players' answers or personal data. They are hints: the
 * authoritative state is always GET /play/{room}/state.
 */
abstract class CompetitionEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly int $sessionId) {}

    public static function channelName(int $sessionId): string
    {
        return 'competition.'.$sessionId;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel(static::channelName($this->sessionId))];
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['session_id' => $this->sessionId, 'server_now' => now()->format('Y-m-d\TH:i:s.vP')] + $this->payload();
    }

    protected static function iso(?\DateTimeInterface $time): ?string
    {
        return $time?->format('Y-m-d\TH:i:s.vP');
    }
}
