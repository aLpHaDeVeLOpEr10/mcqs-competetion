<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum AnnouncementAudience: string
{
    use HasValues;

    case Everyone = 'everyone';
    case Players = 'players';
    case Staff = 'staff';
    case Room = 'room';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Everyone who can sign in',
            self::Players => 'Players only',
            self::Staff => 'Staff only',
            self::Room => 'Players in one competition',
        };
    }
}
