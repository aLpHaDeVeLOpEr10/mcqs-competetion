<?php

namespace App\Contracts\Payments;

use App\Enums\GatewayStatus;

/**
 * A provider's statement about one payment (from a callback or a status
 * query), normalized by the driver. DepositService checks every field
 * against the stored payment before acting on it.
 */
final class GatewayResult
{
    /**
     * @param  array<string, mixed>  $payload  provider data for the callback log (sanitized before storage)
     */
    public function __construct(
        public readonly GatewayStatus $status,
        public readonly string $reference,
        public readonly ?string $providerReference = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $providerStatus = null,
        public readonly ?string $message = null,
        public readonly array $payload = [],
    ) {}
}
