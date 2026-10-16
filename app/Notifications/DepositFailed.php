<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\PaymentTransaction;
use App\Support\Money;

class DepositFailed extends AppNotification
{
    public function __construct(public readonly PaymentTransaction $payment) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Wallet;
    }

    public function title(): string
    {
        return 'Deposit not completed';
    }

    public function body(): string
    {
        return 'Your deposit of '.Money::format($this->payment->amount_minor, $this->payment->currency).' was not completed, so nothing was added to your wallet.';
    }

    public function url(): ?string
    {
        return route('account.deposits.show', $this->payment->reference);
    }
}
