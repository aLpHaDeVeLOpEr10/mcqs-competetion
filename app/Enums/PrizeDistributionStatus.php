<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PrizeDistributionStatus: string
{
    use HasValues;

    case Calculated = 'calculated';
    case Held = 'held';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
