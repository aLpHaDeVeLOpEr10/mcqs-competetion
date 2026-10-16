<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What happens when the start time (or, for fill rooms, the registration
 * deadline) arrives before the room is full.
 */
enum UnderCapacityPolicy: string
{
    use HasValues;

    case Cancel = 'cancel';
    case StartIfMinimum = 'start_if_minimum';

    public function label(): string
    {
        return match ($this) {
            self::Cancel => 'Cancel the competition unless every seat is filled',
            self::StartIfMinimum => 'Start if the minimum number of players has joined; otherwise cancel',
        };
    }
}
