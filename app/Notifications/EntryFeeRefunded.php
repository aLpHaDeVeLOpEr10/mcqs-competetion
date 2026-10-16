<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\Room;
use App\Support\Money;

class EntryFeeRefunded extends AppNotification
{
    public function __construct(public readonly Room $room, public readonly int $amountMinor, public readonly string $reference) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Wallet;
    }

    public function title(): string
    {
        return 'Entry fee refunded';
    }

    public function body(): string
    {
        return Money::format($this->amountMinor, $this->room->currency)." for {$this->room->name} was returned to your wallet.";
    }

    public function url(): ?string
    {
        return route('account.transactions.show', $this->reference);
    }

    protected function extra(): array
    {
        return ['room_id' => $this->room->id];
    }
}
