<?php

namespace App\Services\Withdrawals;

use App\Enums\PayoutMethod;
use App\Exceptions\AdministrativeActionException;
use App\Models\PayoutDestination;
use App\Models\User;
use App\Notifications\PayoutDestinationChanged;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Adding and removing payout accounts. The caller has already confirmed the
 * user's current password (step-up). Account numbers are normalized,
 * validated, encrypted at rest and only ever logged masked.
 */
class PayoutDestinationService
{
    public function __construct(protected AuditLogger $audit, protected Notifier $notifier) {}

    /**
     * Normalize an account number for its method, or null when it is not valid.
     *  - mobile wallet: Pakistani mobile number, stored as 03XXXXXXXXX
     *  - bank account:  Pakistani IBAN (PK + 22 characters) with a valid ISO 13616 checksum
     */
    public static function normalize(PayoutMethod $method, string $number): ?string
    {
        $value = strtoupper(preg_replace('/[\s-]+/', '', $number));

        if ($method === PayoutMethod::MobileWallet) {
            $value = preg_replace('/^(\+92|0092|92)(3\d{9})$/', '0$2', $value);

            return preg_match('/^03\d{9}$/', $value) ? $value : null;
        }

        if (! preg_match('/^PK\d{2}[A-Z]{4}\d{16}$/', $value)) {
            return null;
        }

        // ISO 13616: move the first four characters to the end, letters → numbers, mod 97 must be 1.
        $digits = '';
        foreach (str_split(substr($value, 4).substr($value, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder === 1 ? $value : null;
    }

    public function add(User $user, PayoutMethod $method, string $provider, string $accountTitle, string $number): PayoutDestination
    {
        $normalized = self::normalize($method, $number) ?? throw new AdministrativeActionException('That account number is not valid.');
        if (! array_key_exists($provider, $method->providers())) {
            throw new AdministrativeActionException('Choose a provider from the list.');
        }

        return DB::transaction(function () use ($user, $method, $provider, $accountTitle, $normalized) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $active = PayoutDestination::query()->where('user_id', $user->id)->where('status', 'active');
            if ($active->count() >= (int) config('competition.withdrawals.max_destinations')) {
                throw new AdministrativeActionException('You can keep up to '.config('competition.withdrawals.max_destinations').' payout accounts. Remove one first.');
            }

            $fingerprint = PayoutDestination::fingerprintFor($normalized);
            $destination = PayoutDestination::query()->where('user_id', $user->id)->where('fingerprint', $fingerprint)->first();
            if ($destination?->status === 'active') {
                throw new AdministrativeActionException('This account is already saved.');
            }

            // A re-added account starts a fresh cooling-off period.
            $destination ??= (new PayoutDestination)->forceFill(['user_id' => $user->id, 'fingerprint' => $fingerprint]);
            $destination->forceFill([
                'method' => $method,
                'provider' => $provider,
                'account_title' => $accountTitle,
                'account_number_encrypted' => $normalized,
                'account_last4' => substr($normalized, -4),
                'status' => 'active',
                'removed_at' => null,
                'usable_after' => now()->addHours((int) config('competition.withdrawals.destination_cooldown_hours')),
            ])->save();

            $this->audit->record('payout_destination.added', $destination, null, ['account' => $destination->maskedLabel(), 'method' => $method->value], actor: $user);
            $this->notifier->afterCommit($user, new PayoutDestinationChanged($destination->maskedLabel(), added: true));

            return $destination;
        });
    }

    public function remove(User $user, PayoutDestination $destination): void
    {
        DB::transaction(function () use ($user, $destination) {
            $destination = PayoutDestination::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($destination->id);
            if ($destination->status !== 'active') {
                return;
            }

            // Withdrawals keep their masked snapshot; the row stays for the record.
            $destination->forceFill(['status' => 'removed', 'removed_at' => now()])->save();
            $this->audit->record('payout_destination.removed', $destination, ['status' => 'active'], ['status' => 'removed'], ['account' => $destination->maskedLabel()], $user);
            $this->notifier->afterCommit($user, new PayoutDestinationChanged($destination->maskedLabel(), added: false));
        });
    }
}
