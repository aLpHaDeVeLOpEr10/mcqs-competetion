<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use App\Support\Money;

class WithdrawalUpdated extends AppNotification
{
    public function __construct(public readonly Withdrawal $withdrawal) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Wallet;
    }

    public function title(): string
    {
        return 'Withdrawal '.strtolower($this->withdrawal->status->label());
    }

    public function body(): string
    {
        $amount = Money::format($this->withdrawal->amount_minor, $this->withdrawal->currency);

        return match ($this->withdrawal->status) {
            WithdrawalStatus::Requested => "Your withdrawal of {$amount} is waiting for review. The money is held in your wallet until then.",
            WithdrawalStatus::UnderReview => "Your withdrawal of {$amount} is being reviewed.",
            WithdrawalStatus::Approved => "Your withdrawal of {$amount} was approved and will be sent soon.",
            WithdrawalStatus::Processing => "Your withdrawal of {$amount} has been sent to the payout provider.",
            WithdrawalStatus::Paid => "Your withdrawal of {$amount} was paid to {$this->withdrawal->destinationLabel()}.",
            WithdrawalStatus::Rejected => "Your withdrawal of {$amount} was not approved: {$this->withdrawal->rejection_reason}. The money is back in your wallet.",
            WithdrawalStatus::Failed => "Your withdrawal of {$amount} could not be paid. The money is back in your wallet.",
            WithdrawalStatus::Cancelled => "You cancelled your withdrawal of {$amount}. The money is back in your wallet.",
            WithdrawalStatus::Reversed => "Your withdrawal of {$amount} was returned by the payout provider. The money is back in your wallet.",
        };
    }

    public function url(): ?string
    {
        return route('account.withdrawals.show', $this->withdrawal->reference);
    }
}
