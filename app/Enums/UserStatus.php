<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum UserStatus: string
{
    use HasValues;

    case Active = 'active';
    case Restricted = 'restricted';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Suspended and closed accounts cannot hold a session.
     */
    public function canSignIn(): bool
    {
        return in_array($this, [self::Active, self::Restricted], true);
    }

    /**
     * Competition entry and money movement require a fully active account.
     */
    public function canParticipate(): bool
    {
        return $this === self::Active;
    }
}
