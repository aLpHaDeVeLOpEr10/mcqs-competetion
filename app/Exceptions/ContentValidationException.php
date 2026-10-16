<?php

namespace App\Exceptions;

/**
 * A content action that is permitted but invalid in the current state,
 * carrying the individual problems to show to staff.
 */
class ContentValidationException extends AdministrativeActionException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }
}
