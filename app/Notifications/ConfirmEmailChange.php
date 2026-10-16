<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmEmailChange extends Notification
{
    public function __construct(public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('competition.accounts.email_change_link_minutes');

        return (new MailMessage)
            ->subject('Confirm your new email address')
            ->line('Please confirm that you want to use this address for your '.config('app.name').' account.')
            ->action('Confirm email address', $this->url)
            ->line("This link expires in {$minutes} minutes and only works while you are signed in.")
            ->line('If you did not request this, you can ignore this email.');
    }
}
