<?php

namespace App\Services\Engine;

use App\Models\AnswerSubmission;
use RuntimeException;

class AnswerRejectedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status,
        public readonly ?AnswerSubmission $existing = null,
    ) {
        parent::__construct($message);
    }
}
