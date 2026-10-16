<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;

/**
 * Security notice (always delivered): a payout account was added or removed.
 */
class PayoutDestinationChanged extends AppNotification
{
    public function __construct(public readonly string $maskedAccount, public readonly bool $added) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Security;
    }

    public function title(): string
    {
        return $this->added ? 'A payout account was added' : 'A payout account was removed';
    }

    public function body(): string
    {
        return ($this->added ? 'Added: ' : 'Removed: ').$this->maskedAccount.'. If this was not you, change your password and contact support now.';
    }

    public function url(): ?string
    {
        return route('account.payout-destinations');
    }
}
