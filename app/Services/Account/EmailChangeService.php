<?php

namespace App\Services\Account;

use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Email changes take effect only after the new address confirms them, so the
 * request step never reveals whether another account uses that address.
 */
class EmailChangeService
{
    public const CONFIRMED = 'confirmed';

    public const UNAVAILABLE = 'unavailable';

    public const INVALID = 'invalid';

    public function request(User $user, string $newEmail): void
    {
        $user->forceFill(['pending_email' => $newEmail])->save();

        $url = URL::temporarySignedRoute(
            'account.email.confirm',
            now()->addMinutes((int) config('competition.accounts.email_change_link_minutes')),
            ['hash' => $this->hashFor($newEmail)],
        );

        Notification::route('mail', $newEmail)->notify(new ConfirmEmailChange($url));
        $user->notify(new EmailChangeRequested($newEmail));
    }

    /**
     * @return self::CONFIRMED|self::UNAVAILABLE|self::INVALID
     */
    public function confirm(User $user, string $hash): string
    {
        return DB::transaction(function () use ($user, $hash) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $pending = $user->pending_email;

            if ($pending === null || ! hash_equals($this->hashFor($pending), $hash)) {
                return self::INVALID;
            }

            $taken = User::withTrashed()
                ->where('email', $pending)
                ->whereKeyNot($user->id)
                ->exists();

            if ($taken) {
                $user->forceFill(['pending_email' => null])->save();

                return self::UNAVAILABLE;
            }

            // Following the link proves control of the new address.
            $user->forceFill([
                'email' => $pending,
                'email_verified_at' => now(),
                'pending_email' => null,
            ])->save();

            return self::CONFIRMED;
        });
    }

    public function cancel(User $user): void
    {
        $user->forceFill(['pending_email' => null])->save();
    }

    protected function hashFor(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower($email), config('app.key'));
    }
}
