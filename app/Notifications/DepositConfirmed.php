<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\PaymentTransaction;
use App\Support\Money;

class DepositConfirmed extends AppNotification
{
    public function __construct(public readonly PaymentTransaction $payment) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Wallet;
    }

    public function title(): string
    {
        return 'Deposit confirmed';
    }

    public function body(): string
    {
        return Money::format($this->payment->amount_minor, $this->payment->currency).' was added to your wallet (reference '.$this->payment->reference.').';
    }

    public function url(): ?string
    {
        return route('account.deposits.show', $this->payment->reference);
    }
}
