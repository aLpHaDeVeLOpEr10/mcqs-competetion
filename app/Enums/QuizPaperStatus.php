<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum QuizPaperStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
