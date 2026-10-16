<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Security notice to the current address when an email change is requested.
 */
class EmailChangeRequested extends Notification
{
    public function __construct(public readonly string $newEmail) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Email change requested')
            ->line('A request was made to change your account email to '.Str::mask($this->newEmail, '*', 2, max(0, strpos($this->newEmail, '@') - 2)).'.')
            ->line('The change only happens once the new address is confirmed.')
            ->line('If this was not you, change your password immediately and contact support.')
            ->action('Review account security', route('account.security'));
    }
}
