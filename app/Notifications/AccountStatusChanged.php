<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\UserStatus;

/**
 * Security notice: always delivered (cannot be switched off).
 */
class AccountStatusChanged extends AppNotification
{
    public function __construct(public readonly UserStatus $status) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Security;
    }

    public function title(): string
    {
        return 'Your account status changed to '.strtolower($this->status->label());
    }

    public function body(): string
    {
        return match ($this->status) {
            UserStatus::Active => 'Your account is fully active again.',
            UserStatus::Restricted => 'You can sign in, but you cannot join competitions or make payments for now. Contact support if you have questions.',
            default => 'You can no longer sign in. Contact support if you think this is a mistake.',
        };
    }

    public function url(): ?string
    {
        return route('contact');
    }
}
