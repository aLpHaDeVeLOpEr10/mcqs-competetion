<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum DisputeStatus: string
{
    use HasValues;

    case Open = 'open';
    case Rejected = 'rejected';
    case Resolved = 'resolved';
}
