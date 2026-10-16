<?php

namespace App\Contracts\Payments;

final class EntryPaymentOutcome
{
    private function __construct(
        public readonly bool $confirmed,
        public readonly ?string $reference = null,
    ) {}

    public static function confirmed(?string $reference = null): self
    {
        return new self(true, $reference);
    }

    /**
     * Hold the seat as a reservation until the payment is confirmed.
     */
    public static function pending(?string $reference = null): self
    {
        return new self(false, $reference);
    }
}
