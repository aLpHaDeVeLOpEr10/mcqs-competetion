<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum WalletType: string
{
    use HasValues;

    case User = 'user';
    case System = 'system';
}
