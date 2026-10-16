<?php

namespace App\Exceptions;

use App\Enums\JoinBlockReason;
use RuntimeException;

/**
 * The server refused a room entry. Carries the reason shown to the player.
 */
class EntryDeniedException extends RuntimeException
{
    public function __construct(public readonly JoinBlockReason $reason)
    {
        parent::__construct($reason->message());
    }
}
