<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum QuestionStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
