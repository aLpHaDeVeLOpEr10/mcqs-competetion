<?php

namespace App\Models;

use App\Enums\LedgerTransactionType;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One balanced business event in the wallet ledger. Append-only.
 */
class LedgerTransaction extends Model
{
    use Immutable;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Written only by the ledger service.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected static function booted(): void
    {
        // Public reference shown to players and support; never the id or idempotency key.
        static::creating(fn (LedgerTransaction $tx) => $tx->reference ??= (string) Str::ulid());
    }

    /**
     * Posted transactions are final. "reversed" is derived from a later
     * reversal transaction, because ledger rows are never updated.
     */
    public function status(): string
    {
        $reversed = array_key_exists('reversal_exists', $this->attributes) ? (bool) $this->reversal_exists : $this->reversal()->exists();

        return $reversed ? 'reversed' : 'completed';
    }

    protected function casts(): array
    {
        return [
            'type' => LedgerTransactionType::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        // What the transaction settled (seat, payment, prize). Stored in reference_type/reference_id;
        // the "reference" attribute itself is the public transaction reference.
        return $this->morphTo('related', 'reference_type', 'reference_id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function reversedTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'reverses_transaction_id');
    }

    /**
     * @return HasOne<LedgerTransaction, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(LedgerTransaction::class, 'reverses_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
