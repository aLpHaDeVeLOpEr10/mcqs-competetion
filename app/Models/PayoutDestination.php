<?php

namespace App\Models;

use App\Enums\PayoutMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's payout account. The account number is stored encrypted (it is
 * genuinely needed to pay out), never serialized, and shown only masked.
 */
class PayoutDestination extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected $hidden = ['account_number_encrypted', 'fingerprint'];

    protected function casts(): array
    {
        return [
            'method' => PayoutMethod::class,
            'account_number_encrypted' => 'encrypted',
            'usable_after' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * Keyed hash of the normalized number: detects duplicates without storing it twice in clear.
     */
    public static function fingerprintFor(string $normalizedNumber): string
    {
        return hash_hmac('sha256', $normalizedNumber, (string) config('app.key'));
    }

    /**
     * e.g. "Meezan Bank •••• 4321".
     */
    public function maskedLabel(): string
    {
        return $this->providerName().' •••• '.$this->account_last4;
    }

    public function providerName(): string
    {
        return $this->method->providers()[$this->provider] ?? $this->provider;
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->usable_after->isPast();
    }

    /**
     * Masked details kept on each withdrawal (never the full number).
     *
     * @return array<string, string>
     */
    public function snapshot(): array
    {
        return [
            'method' => $this->method->value,
            'provider' => $this->provider,
            'provider_name' => $this->providerName(),
            'account_title' => $this->account_title,
            'last4' => $this->account_last4,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
