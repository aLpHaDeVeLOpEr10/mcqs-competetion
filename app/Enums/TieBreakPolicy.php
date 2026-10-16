<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum TieBreakPolicy: string
{
    use HasValues;

    case FastestCorrect = 'fastest_correct';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::FastestCorrect => 'Equal scores are ranked by lower total response time on correct answers; exact ties share the rank',
            self::Shared => 'Equal scores share the same rank',
        };
    }
}
