<?php

namespace App\Services\Competition;

use App\Enums\JoinBlockReason;

/**
 * Server-side answer to "can this user join this room now?".
 */
final class JoinEligibility
{
    private function __construct(public readonly ?JoinBlockReason $reason) {}

    public static function allowed(): self
    {
        return new self(null);
    }

    public static function blocked(JoinBlockReason $reason): self
    {
        return new self($reason);
    }

    public function canJoin(): bool
    {
        return $this->reason === null;
    }

    public function label(): string
    {
        return $this->reason?->label() ?? 'Join competition';
    }

    public function message(): ?string
    {
        return $this->reason?->message();
    }
}
