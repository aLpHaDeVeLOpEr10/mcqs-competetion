<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Support\Money;

class RoomJoined extends AppNotification
{
    public function __construct(public readonly Room $room, public readonly RoomParticipant $seat) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Competition;
    }

    public function title(): string
    {
        return "You're registered for {$this->room->name}";
    }

    public function body(): string
    {
        return 'Your seat is confirmed. Entry: '.Money::fee($this->seat->entry_fee_minor, $this->room->currency).'.';
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
