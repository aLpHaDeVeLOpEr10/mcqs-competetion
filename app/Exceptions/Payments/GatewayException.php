<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * The provider could not be reached, timed out, or refused the request. The
 * payment's outcome is unknown, so nothing is concluded from it.
 */
class GatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $timeout = false)
    {
        parent::__construct($message);
    }

    public static function timeout(string $operation): self
    {
        return new self("The payment provider did not respond in time ({$operation}).", true);
    }

    public static function unavailable(string $message = 'The payment provider is unavailable.'): self
    {
        return new self($message);
    }
}
