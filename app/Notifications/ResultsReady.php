<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\Room;

class ResultsReady extends AppNotification
{
    public function __construct(public readonly Room $room) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Results;
    }

    public function title(): string
    {
        return "Results for {$this->room->name} are published";
    }

    public function body(): string
    {
        return 'See your rank, score and the correct answers.';
    }

    public function url(): ?string
    {
        return route('competitions.results', $this->room);
    }

    protected function extra(): array
    {
        return ['room_id' => $this->room->id];
    }
}
