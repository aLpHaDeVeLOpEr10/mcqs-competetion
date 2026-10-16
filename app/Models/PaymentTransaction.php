<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PaymentTransaction extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Written only by the payment service.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected $hidden = [
        'gateway_payload',
        'checkout',
    ];

    protected static function booted(): void
    {
        // Internal payment reference: sent to the provider and shown to the player.
        static::creating(fn (PaymentTransaction $payment) => $payment->reference ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'gateway_payload' => 'array',
            'completed_at' => 'datetime',
            'checkout' => 'array',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }

    /**
     * @return HasMany<PaymentCallback, $this>
     */
    public function callbacks(): HasMany
    {
        return $this->hasMany(PaymentCallback::class);
    }

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }
}
