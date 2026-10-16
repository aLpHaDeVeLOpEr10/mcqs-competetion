<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\Room;

class CompetitionStarted extends AppNotification
{
    public function __construct(public readonly Room $room) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Competition;
    }

    public function title(): string
    {
        return "{$this->room->name} has started";
    }

    public function body(): string
    {
        return 'The first question is live now.';
    }

    public function url(): ?string
    {
        return route('play.show', $this->room);
    }

    protected function extra(): array
    {
        return ['room_id' => $this->room->id];
    }
}
