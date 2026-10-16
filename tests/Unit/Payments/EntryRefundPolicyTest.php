<?php

namespace Tests\Unit\Payments;

use App\Models\Room;
use App\Services\Payments\EntryRefundPolicy;
use Tests\TestCase;

class EntryRefundPolicyTest extends TestCase
{
    public function test_cancellations_and_lapsed_reservations_are_always_refundable(): void
    {
        $policy = new EntryRefundPolicy;

        foreach ([true, false] as $refundOnWithdrawal) {
            config(['competition.entry_fees.refund_on_withdrawal' => $refundOnWithdrawal]);
            $this->assertTrue($policy->refundable(EntryRefundPolicy::ROOM_CANCELLED));
            $this->assertTrue($policy->refundable(EntryRefundPolicy::RESERVATION_EXPIRED));
        }
    }

    public function test_withdrawal_follows_the_configuration(): void
    {
        $policy = new EntryRefundPolicy;

        config(['competition.entry_fees.refund_on_withdrawal' => true]);
        $this->assertTrue($policy->refundable(EntryRefundPolicy::WITHDRAWN));

        config(['competition.entry_fees.refund_on_withdrawal' => false]);
        $this->assertFalse($policy->refundable(EntryRefundPolicy::WITHDRAWN));
    }

    public function test_anything_else_is_never_refundable(): void
    {
        $policy = new EntryRefundPolicy;

        foreach (['completed', 'disqualified', 'finished', '', 'ROOM_CANCELLED'] as $reason) {
            $this->assertFalse($policy->refundable($reason), $reason);
        }
    }

    public function test_descriptions_quote_the_room_and_reason(): void
    {
        $room = (new Room)->forceFill(['name' => 'Evening Quiz', 'cancellation_reason' => 'Not enough players']);

        $this->assertSame('Evening Quiz — competition cancelled (Not enough players)', (new EntryRefundPolicy)->describe($room, EntryRefundPolicy::ROOM_CANCELLED));
        $this->assertSame('Evening Quiz — withdrew before the deadline', (new EntryRefundPolicy)->describe($room, EntryRefundPolicy::WITHDRAWN));
        // Long names are shortened to fit ledger_transactions.description (255).
        $long = (new EntryRefundPolicy)->describe((new Room)->forceFill(['name' => str_repeat('x', 300)]), EntryRefundPolicy::WITHDRAWN);
        $this->assertLessThanOrEqual(255, mb_strlen($long));
        $this->assertStringEndsWith('...', $long);
    }
}
