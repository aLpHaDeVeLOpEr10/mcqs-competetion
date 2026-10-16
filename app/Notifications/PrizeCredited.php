<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\PrizePayout;
use App\Support\Money;

/**
 * Sent after a prize credit has been committed to the winner's wallet.
 */
class PrizeCredited extends AppNotification
{
    public function __construct(public readonly PrizePayout $payout) {}

    public function category(): NotificationCategory
    {
        return NotificationCategory::Prizes;
    }

    public function title(): string
    {
        return 'You won a prize in '.$this->payout->participant->room->name;
    }

    public function body(): string
    {
        return 'You finished #'.$this->payout->rank.'. '
            .Money::format((int) $this->payout->net_amount_minor, $this->payout->participant->room->currency).' has been added to your wallet.';
    }

    public function url(): ?string
    {
        return route('competitions.results', $this->payout->participant->room);
    }

    protected function extra(): array
    {
        return ['room_id' => $this->payout->participant->room_id, 'rank' => $this->payout->rank];
    }
}
