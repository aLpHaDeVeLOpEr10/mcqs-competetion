<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;

/**
 * A user's channel choices per notification category, on top of the
 * category defaults. Locked channels (security notices, the in-app record
 * of money movements) are always delivered.
 */
class NotificationPreferences
{
    /** Channels a user can choose per category. */
    public const CHANNELS = ['database', 'mail'];

    public function wants(User $user, NotificationCategory $category, string $channel): bool
    {
        if (in_array($channel, $category->locked(), true)) {
            return true;
        }

        $stored = $user->notification_preferences[$category->value][$channel] ?? null;

        return is_bool($stored) ? $stored : ($category->defaults()[$channel] ?? false);
    }

    /**
     * The effective matrix for the preferences form.
     *
     * @return array<string, array<string, array{enabled: bool, locked: bool}>>
     */
    public function matrix(User $user): array
    {
        $matrix = [];
        foreach (NotificationCategory::cases() as $category) {
            foreach (self::CHANNELS as $channel) {
                $matrix[$category->value][$channel] = [
                    'enabled' => $this->wants($user, $category, $channel),
                    'locked' => in_array($channel, $category->locked(), true),
                ];
            }
        }

        return $matrix;
    }

    /**
     * Store the user's choices. Locked channels are ignored (always on).
     *
     * @param  array<string, array<string, mixed>>  $choices
     */
    public function update(User $user, array $choices): void
    {
        $stored = [];
        foreach (NotificationCategory::cases() as $category) {
            foreach (self::CHANNELS as $channel) {
                if (! in_array($channel, $category->locked(), true)) {
                    $stored[$category->value][$channel] = (bool) ($choices[$category->value][$channel] ?? false);
                }
            }
        }

        $user->forceFill(['notification_preferences' => $stored])->save();
    }
}
