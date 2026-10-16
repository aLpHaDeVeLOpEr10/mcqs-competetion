<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum RefundStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
