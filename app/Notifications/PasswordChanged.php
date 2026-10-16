<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Security notice: always delivered (cannot be switched off).
 */
class PasswordChanged extends AppNotification
{
    public function category(): NotificationCategory
    {
        return NotificationCategory::Security;
    }

    public function title(): string
    {
        return 'Your password was changed';
    }

    public function body(): string
    {
        return 'The password for your account was just changed, and other signed-in sessions were signed out. If you did not do this, reset your password now and contact support.';
    }

    public function url(): ?string
    {
        return route('account.security');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your password was changed')
            ->line('The password for your '.config('app.name').' account was just changed, and other signed-in sessions were signed out.')
            ->line('If you did not do this, reset your password now and contact support.')
            ->action('Reset password', route('password.request'));
    }
}
