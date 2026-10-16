<?php

namespace App\Contracts\Payments;

use Carbon\CarbonInterface;

/**
 * What the provider needs to create a payment. The amount comes from the
 * stored payment record, never from the browser.
 */
final class PaymentRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $description,
        public readonly string $returnUrl,
        public readonly string $callbackUrl,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
