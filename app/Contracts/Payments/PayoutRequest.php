<?php

namespace App\Contracts\Payments;

/**
 * What a payout provider needs. The account number is decrypted only in memory
 * for this call and must never be logged by drivers.
 */
final class PayoutRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $method,
        public readonly string $provider,
        public readonly string $accountTitle,
        #[\SensitiveParameter] public readonly string $accountNumber,
        public readonly string $callbackUrl,
    ) {}

    /**
     * Never let the account number reach a log or dump.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['reference' => $this->reference, 'amountMinor' => $this->amountMinor, 'accountNumber' => '[hidden]'];
    }
}
