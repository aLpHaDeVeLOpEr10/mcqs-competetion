<?php

namespace App\Services\Account;

use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\AccountAlreadyExists;
use App\Notifications\WelcomeToPlatform;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Creates player accounts without revealing whether an email is registered:
 * the caller gets the same outcome either way, and the address owner is
 * emailed in both cases.
 */
class RegistrationService
{
    /**
     * @param  array{name: string, display_name: string, email: string, password: string}  $data
     */
    public function register(array $data): void
    {
        if ($this->emailIsTaken($data['email'])) {
            $this->handleExistingEmail($data);

            return;
        }

        try {
            $user = DB::transaction(function () use ($data) {
                $user = new User([
                    'name' => $data['name'],
                    'display_name' => $data['display_name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                ]);
                $user->terms_accepted_at = now();
                $user->save();

                // Zero-balance wallet; balances only ever change through the ledger.
                (new Wallet)->forceFill([
                    'type' => WalletType::User,
                    'user_id' => $user->id,
                    'currency' => config('competition.currency.code'),
                ])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race with a concurrent registration.
            if (User::withTrashed()->where('display_name', $data['display_name'])->exists()
                && ! $this->emailIsTaken($data['email'])) {
                throw ValidationException::withMessages(['display_name' => 'That display name is already taken.']);
            }

            $this->handleExistingEmail($data, alreadyHashed: true);

            return;
        }

        // Sends the verification email when verification is enabled.
        event(new Registered($user));

        if (! User::requiresEmailVerification()) {
            $user->notify(new WelcomeToPlatform);
        }
    }

    protected function emailIsTaken(string $email): bool
    {
        return User::withTrashed()->where('email', $email)->exists();
    }

    /**
     * Do comparable work to a real registration (password hashing), then tell
     * the address owner instead of the requester.
     *
     * @param  array{password: string, email: string}  $data
     */
    protected function handleExistingEmail(array $data, bool $alreadyHashed = false): void
    {
        if (! $alreadyHashed) {
            Hash::make($data['password']);
        }

        Notification::route('mail', $data['email'])->notify(new AccountAlreadyExists);
    }
}
