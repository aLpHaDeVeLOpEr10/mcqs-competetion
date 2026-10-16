<?php

namespace App\Models;

use App\Enums\PrizeDistributionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrizeDistribution extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => PrizeDistributionStatus::class,
            'total_amount_minor' => 'integer',
            'calculation' => 'array',
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'gross_entry_fees_minor' => 'integer',
            'refunds_minor' => 'integer',
            'net_entry_fees_minor' => 'integer',
            'platform_amount_minor' => 'integer',
            'prize_pool_minor' => 'integer',
            'unallocated_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CompetitionSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CompetitionSession::class, 'competition_session_id');
    }

    /**
     * @return HasMany<PrizePayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(PrizePayout::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
