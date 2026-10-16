<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A withdrawal request. Written only by WithdrawalService; every state change
 * is audited. The record is kept forever (rejections and failures included).
 */
class Withdrawal extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::creating(fn (Withdrawal $w) => $w->reference ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'destination_snapshot' => 'array',
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'review_started_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'processing_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * Total taken from the wallet (amount plus the disclosed fee).
     */
    public function totalMinor(): int
    {
        return $this->amount_minor + $this->fee_minor;
    }

    public function destinationLabel(): string
    {
        $s = $this->destination_snapshot ?? [];

        return ($s['provider_name'] ?? '?').' •••• '.($s['last4'] ?? '????');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PayoutDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(PayoutDestination::class, 'payout_destination_id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function holdTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'hold_ledger_transaction_id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function releaseTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'release_ledger_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
