<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrizePayout extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'rank' => 'integer',
            'amount_minor' => 'integer',
            'tax_withheld_minor' => 'integer',
            'net_amount_minor' => 'integer',
            'result_version' => 'integer',
            'score' => 'integer',
            'correct_count' => 'integer',
            'timing_ms' => 'integer',
            'rule' => 'array',
            'paid_at' => 'datetime',
            'approved_at' => 'datetime',
            'held_at' => 'datetime',
            'processing_at' => 'datetime',
            'failed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PrizeDistribution, $this>
     */
    public function distribution(): BelongsTo
    {
        return $this->belongsTo(PrizeDistribution::class, 'prize_distribution_id');
    }

    /**
     * @return BelongsTo<RoomParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(RoomParticipant::class, 'room_participant_id');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function reversalLedgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'reversal_ledger_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<LedgerTransaction, $this>
     */
    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }
}
