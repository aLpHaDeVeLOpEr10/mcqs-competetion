<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum LedgerTransactionType: string
{
    use HasValues;

    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case EntryFee = 'entry_fee';
    case EntryRefund = 'entry_refund';
    case PrizePayout = 'prize_payout';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
    case PlatformFee = 'platform_fee';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::EntryFee => 'Entry fee',
            self::EntryRefund => 'Entry fee refund',
            self::PrizePayout => 'Prize',
            self::Adjustment => 'Adjustment',
            self::Reversal => 'Reversal',
            self::PlatformFee => 'Platform fee',
        };
    }
}
