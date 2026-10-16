<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum QuestionDifficulty: string
{
    use HasValues;

    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';
}
