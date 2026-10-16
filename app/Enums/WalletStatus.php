<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum WalletStatus: string
{
    use HasValues;

    case Active = 'active';
    case Frozen = 'frozen';
}
