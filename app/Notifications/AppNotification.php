<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Base for every user-facing notification.
 *
 * Channels (decided per user from NotificationPreferences):
 *  - database:  the in-app record. Delivered synchronously (sync connection).
 *  - broadcast: real-time hint on the user's PRIVATE channel App.Models.User.{id}.
 *               Payload is id, category, title and link only; the body (which may
 *               mention amounts) is never broadcast.
 *  - mail:      queued on the default queue connection; only for verified addresses.
 *  - extra:     competition.notifications.extra_channels (e.g. "sms") are used when
 *               the notification defines to{Channel}() — the extension point.
 *
 * Every notification is dispatched after the surrounding database commit, so it
 * never describes something that was rolled back, and a delivery failure can
 * never roll back the business operation (see Notifier).
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Retries for queued (mail) delivery. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    abstract public function category(): NotificationCategory;

    abstract public function title(): string;

    abstract public function body(): string;

    public function url(): ?string
    {
        return null;
    }

    /**
     * Extra non-sensitive data kept with the in-app record.
     *
     * @return array<string, mixed>
     */
    protected function extra(): array
    {
        return [];
    }

    public function type(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        $preferences = app(NotificationPreferences::class);
        $channels = [];

        if ($preferences->wants($notifiable, $this->category(), 'database')) {
            $channels[] = 'database';

            if (config('competition.notifications.broadcast') && config('broadcasting.default') !== 'null') {
                $channels[] = 'broadcast';
            }
        }

        if (config('competition.notifications.mail') && $notifiable->email && $notifiable->hasVerifiedEmail()
            && $preferences->wants($notifiable, $this->category(), 'mail')) {
            $channels[] = 'mail';
        }

        foreach (config('competition.notifications.extra_channels', []) as $channel) {
            if (method_exists($this, 'to'.Str::studly($channel)) && $preferences->wants($notifiable, $this->category(), $channel)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * In-app and real-time delivery happen immediately; everything else is queued.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync', 'broadcast' => 'sync'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'category' => $this->category()->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
        ] + $this->extra();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'category' => $this->category()->value,
            'title' => $this->title(),
            'url' => $this->url(),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title())->line($this->body());

        return $this->url() ? $mail->action('Open', $this->url()) : $mail;
    }
}
