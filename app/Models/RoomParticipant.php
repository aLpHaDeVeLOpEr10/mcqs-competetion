<?php

namespace App\Models;

use App\Enums\ParticipantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RoomParticipant extends Model
{
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Rows are created only by the entry service; nothing is mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected $attributes = [
        'status' => 'joined',
        'entry_fee_minor' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ParticipantStatus::class,
            'entry_fee_minor' => 'integer',
            'joined_at' => 'datetime',
            'reserved_until' => 'datetime',
            'confirmed_at' => 'datetime',
            'expired_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function entryLedgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'entry_ledger_transaction_id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function refundLedgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'refund_ledger_transaction_id');
    }

    /**
     * @return HasMany<AnswerSubmission, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(AnswerSubmission::class);
    }

    /**
     * @return HasOne<ParticipantResult, $this>
     */
    public function result(): HasOne
    {
        return $this->hasOne(ParticipantResult::class);
    }

    /**
     * @return HasOne<PrizePayout, $this>
     */
    public function prizePayout(): HasOne
    {
        return $this->hasOne(PrizePayout::class);
    }
}
