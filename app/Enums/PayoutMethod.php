<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum PayoutMethod: string
{
    use HasValues;

    case BankAccount = 'bank_account';
    case MobileWallet = 'mobile_wallet';

    public function label(): string
    {
        return match ($this) {
            self::BankAccount => 'Bank account (IBAN)',
            self::MobileWallet => 'Mobile wallet',
        };
    }

    /**
     * Approved providers for this method (config competition.withdrawals.providers).
     *
     * @return array<string, string>
     */
    public function providers(): array
    {
        return config("competition.withdrawals.providers.{$this->value}", []);
    }
}
