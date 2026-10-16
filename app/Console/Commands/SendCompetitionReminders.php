<?php

namespace App\Console\Commands;

use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\User;
use App\Notifications\CompetitionReminder;
use App\Services\Notifications\Notifier;
use Illuminate\Console\Command;

/**
 * "Starts in N minutes" reminders for registered players (offsets from
 * competition.notifications.reminder_minutes). Each player gets each
 * reminder at most once: a unique key per room, start time, offset and user
 * is claimed in notification_dispatches before sending. If the scheduler
 * runs late, only the nearest due reminder is sent (never a burst).
 */
class SendCompetitionReminders extends Command
{
    protected $signature = 'notifications:reminders';

    protected $description = 'Send competition starting-soon reminders (each at most once per player)';

    public function handle(Notifier $notifier): int
    {
        $offsets = collect(config('competition.notifications.reminder_minutes', []))->map(fn ($m) => (int) $m)->filter(fn ($m) => $m > 0)->sort()->values();
        if ($offsets->isEmpty()) {
            return self::SUCCESS;
        }

        $now = now();
        $rooms = Room::query()
            ->whereIn('status', [RoomStatus::Open, RoomStatus::Full, RoomStatus::Starting])
            ->whereRaw('COALESCE(starts_at, scheduled_start_at) > ?', [$now])
            ->whereRaw('COALESCE(starts_at, scheduled_start_at) <= ?', [$now->copy()->addMinutes($offsets->max())])
            ->get();

        $sent = 0;
        foreach ($rooms as $room) {
            $start = $room->starts_at ?? $room->scheduled_start_at;
            $secondsLeft = $now->diffInSeconds($start);
            // The smallest offset that is due (e.g. 4 minutes left → the 5-minute reminder).
            $offset = $offsets->first(fn ($m) => $secondsLeft <= $m * 60);
            if ($offset === null) {
                continue;
            }

            $users = User::query()->whereIn('id', $room->participants()->where('status', ParticipantStatus::Joined)->select('user_id'))->get()
                ->filter(fn (User $user) => $notifier->claimOnce("reminder:{$room->id}:{$start->getTimestamp()}:{$offset}:{$user->id}", $user->id));

            if ($users->isNotEmpty()) {
                $notifier->send($users, new CompetitionReminder($room, max(1, (int) ceil($secondsLeft / 60))));
                $sent += $users->count();
            }
        }

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
