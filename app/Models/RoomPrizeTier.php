<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomPrizeTier extends Model
{
    protected $fillable = [
        'room_id',
        'rank_from',
        'rank_to',
        'amount_minor',
        'percent_bp',
    ];

    protected function casts(): array
    {
        return [
            'rank_from' => 'integer',
            'rank_to' => 'integer',
            'amount_minor' => 'integer',
            'percent_bp' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function positions(): int
    {
        return $this->rank_to - $this->rank_from + 1;
    }

    /**
     * Total paid by this fixed-amount tier when every rank in it is awarded.
     */
    public function totalAmountMinor(): int
    {
        return $this->positions() * $this->amount_minor;
    }

    /**
     * "12.5%" for percentage tiers (basis points).
     */
    public function percentLabel(): string
    {
        return rtrim(rtrim(number_format(($this->percent_bp ?? 0) / 100, 2), '0'), '.').'%';
    }
}
