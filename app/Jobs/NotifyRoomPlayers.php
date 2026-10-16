<?php

namespace App\Jobs;

use App\Models\RoomParticipant;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one notice to every player of a room with the given seat statuses, in
 * chunks of 500. Each chunk is its own short job (it dispatches the next), so a
 * large room never holds up the competition engine or exceeds a job timeout.
 *
 * Exactly once per player: each recipient is claimed in notification_dispatches
 * before sending, so a retried or duplicated chunk skips players already notified.
 */
class NotifyRoomPlayers implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 500;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @param  list<string>  $statuses  seat statuses (ParticipantStatus values)
     */
    public function __construct(
        public readonly int $roomId,
        public readonly array $statuses,
        public readonly AppNotification $notification,
        public readonly int $afterUserId = 0,
    ) {
        $this->onQueue(config('competition.queues.default'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(Notifier $notifier): void
    {
        $users = User::query()
            ->whereIn('id', RoomParticipant::query()->where('room_id', $this->roomId)->whereIn('status', $this->statuses)->select('user_id'))
            ->where('id', '>', $this->afterUserId)
            ->orderBy('id')->limit(self::CHUNK)->get();

        if ($users->isEmpty()) {
            return;
        }

        $key = 'room-notice:'.class_basename($this->notification).':'.$this->roomId.':';
        $fresh = $users->filter(fn (User $user) => $notifier->claimOnce($key.$user->id, $user->id));
        if ($fresh->isNotEmpty()) {
            $notifier->send($fresh, $this->notification);
        }

        if ($users->count() === self::CHUNK) {
            self::dispatch($this->roomId, $this->statuses, $this->notification, (int) $users->last()->id);
        }
    }
}
