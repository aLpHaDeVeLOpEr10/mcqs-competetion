<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent on registration when email verification is disabled, so a new
 * registration always produces an email, like the existing-account path.
 */
class WelcomeToPlatform extends Notification
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
            ->subject('Welcome to '.config('app.name'))
            ->greeting('Welcome, '.$notifiable->publicName().'!')
            ->line('Your account is ready.')
            ->action('Sign in', route('login'));
    }
}
