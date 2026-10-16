<?php

namespace App\Services\Notifications;

use App\Enums\ParticipantStatus;
use App\Jobs\NotifyRoomPlayers;
use App\Models\User;
use App\Notifications\AppNotification;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The one way services send notifications. Delivery happens after the
 * surrounding transaction commits, and any delivery failure is logged and
 * swallowed: a notification can never undo a join, a payment or a result.
 */
class Notifier
{
    /**
     * @param  User|iterable<User>|Closure(): (User|iterable<User>)  $recipients  resolved after commit when a closure
     */
    public function afterCommit(User|iterable|Closure $recipients, AppNotification $notification): void
    {
        DB::afterCommit(fn () => $this->send($recipients instanceof Closure ? $recipients() : $recipients, $notification));
    }

    /**
     * A notice for every player of a room (by seat status), sent after commit by a
     * queued, chunked job, so a large room never slows the operation that caused it.
     * Each player receives it at most once.
     *
     * @param  list<ParticipantStatus>  $statuses
     */
    public function toRoom(int $roomId, array $statuses, AppNotification $notification): void
    {
        $values = array_map(fn ($status) => $status->value, $statuses);
        DB::afterCommit(fn () => NotifyRoomPlayers::dispatch($roomId, $values, $notification));
    }

    /**
     * @param  User|iterable<User>  $recipients
     */
    public function send(User|iterable $recipients, AppNotification $notification): void
    {
        try {
            Notification::send($recipients, $notification);
        } catch (Throwable $e) {
            Log::error('Notification delivery failed; the operation it describes is unaffected.', [
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Claim a "send once" key for a user. Returns false when it was already sent.
     */
    public function claimOnce(string $key, int $userId): bool
    {
        return DB::table('notification_dispatches')->insertOrIgnore([
            'key' => mb_substr($key, 0, 191),
            'user_id' => $userId,
            'created_at' => now(),
        ]) === 1;
    }
}
