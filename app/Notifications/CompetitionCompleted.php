<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\Room;

class CompetitionCompleted extends AppNotification
{
    public function __construct(public readonly Room $room) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Results;
    }

    public function title(): string
    {
        return "{$this->room->name} has finished";
    }

    public function body(): string
    {
        return 'Results are being checked and will be published soon.';
    }

    public function url(): ?string
    {
        return route('account.competitions.show', $this->room);
    }

    protected function extra(): array
    {
        return ['room_id' => $this->room->id];
    }
}
