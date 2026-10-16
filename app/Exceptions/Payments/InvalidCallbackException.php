<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * A provider notification that failed authentication or could not be parsed.
 * It is logged and ignored; it never changes a payment.
 */
class InvalidCallbackException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $signatureValid = false)
    {
        parent::__construct("Invalid payment callback: {$reason}");
    }
}
