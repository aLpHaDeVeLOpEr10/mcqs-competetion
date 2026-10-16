<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Notifications\AnnouncementPublished;
use App\Services\Notifications\AnnouncementService;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Delivers an announcement in chunks. Safe to retry: each user is claimed in
 * notification_dispatches first, so nobody receives the same announcement twice.
 */
class SendAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $announcementId)
    {
        // Long-running: its own connection, whose retry_after exceeds the timeout.
        $this->onConnection(config('competition.queues.long_connection'))->onQueue(config('competition.queues.long'));
    }

    public function handle(AnnouncementService $announcements, Notifier $notifier): void
    {
        $announcement = Announcement::query()->find($this->announcementId);
        if ($announcement === null || $announcement->sent_at !== null) {
            return;
        }

        $notification = new AnnouncementPublished($announcement);

        $announcements->recipients($announcement)->select('users.*')->chunkById(500, function (Collection $users) use ($announcement, $notification, $notifier) {
            $fresh = $users->filter(fn ($user) => $notifier->claimOnce("announcement:{$announcement->id}:{$user->id}", $user->id));
            if ($fresh->isNotEmpty()) {
                $notifier->send($fresh, $notification);
            }
        });

        $announcement->forceFill([
            'recipients_count' => DB::table('notification_dispatches')->where('key', 'like', "announcement:{$announcement->id}:%")->count(),
            'sent_at' => now(),
        ])->save();
    }
}
