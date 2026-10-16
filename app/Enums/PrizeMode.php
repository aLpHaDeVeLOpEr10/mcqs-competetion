<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PrizeMode: string
{
    use HasValues;

    case Fixed = 'fixed';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amounts per rank',
            self::Percentage => 'Percentage of a defined prize pool',
        };
    }
}
