<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent instead of a second account when someone registers with an address
 * that already has one. The requester sees the same response either way.
 */
class AccountAlreadyExists extends Notification
{
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
            ->subject('You already have an account')
            ->line('Someone tried to create a new '.config('app.name').' account using this email address, which is already registered.')
            ->line('If it was you, you can sign in, or reset your password if you have forgotten it.')
            ->action('Reset password', route('password.request'))
            ->line('If it was not you, no action is needed. Your account has not been changed.');
    }
}
