<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PaymentType: string
{
    use HasValues;

    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
}
