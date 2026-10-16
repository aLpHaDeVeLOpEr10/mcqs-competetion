<?php

namespace App\Contracts\Payments;

/**
 * A provider's statement about one payout, normalized by the driver.
 * Status: processing | paid | failed | reversed | not_found.
 */
final class PayoutResult
{
    public const PROCESSING = 'processing';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const REVERSED = 'reversed';

    public const NOT_FOUND = 'not_found';

    public function __construct(
        public readonly string $status,
        public readonly string $reference,
        public readonly ?string $providerReference = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $providerStatus = null,
        public readonly ?string $message = null,
    ) {}
}
