<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\Room;

class CompetitionReminder extends AppNotification
{
    public function __construct(public readonly Room $room, public readonly int $minutes) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Competition;
    }

    public function title(): string
    {
        return "{$this->room->name} starts in {$this->minutes} ".($this->minutes === 1 ? 'minute' : 'minutes');
    }

    public function body(): string
    {
        return 'Be ready: every player receives each question at the same moment.';
    }

    public function url(): ?string
    {
        return route('competitions.show', $this->room);
    }

    protected function extra(): array
    {
        return ['room_id' => $this->room->id, 'minutes' => $this->minutes];
    }
}
