<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\Announcement;

class AnnouncementPublished extends AppNotification
{
    public function __construct(public readonly Announcement $announcement) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Announcements;
    }

    public function title(): string
    {
        return $this->announcement->title;
    }

    public function body(): string
    {
        return $this->announcement->body;
    }

    protected function extra(): array
    {
        return ['announcement_id' => $this->announcement->id];
    }
}
