<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a percentage prize pool comes from. Never "all entry fees": the
 * entry-fee basis is net fees (after refunds) minus the platform fee.
 */
enum PrizePoolBasis: string
{
    use HasValues;

    case Guaranteed = 'guaranteed';
    case EntryFees = 'entry_fees';

    public function label(): string
    {
        return match ($this) {
            self::Guaranteed => 'Guaranteed pool (fixed amount)',
            self::EntryFees => 'Net entry fees minus the platform fee',
        };
    }
}
