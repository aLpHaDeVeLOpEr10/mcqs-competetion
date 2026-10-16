<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Groups of notifications a user can tune. A channel listed in locked() is
 * always delivered: users cannot switch off security notices or the in-app
 * record of money movements.
 */
enum NotificationCategory: string
{
    use HasValues;

    case Competition = 'competition';
    case Results = 'results';
    case Prizes = 'prizes';
    case Wallet = 'wallet';
    case Security = 'security';
    case Announcements = 'announcements';

    public function label(): string
    {
        return match ($this) {
            self::Competition => 'Competitions you joined (joining, reminders, start)',
            self::Results => 'Results',
            self::Prizes => 'Prizes',
            self::Wallet => 'Deposits and refunds',
            self::Security => 'Account and security',
            self::Announcements => 'Announcements from the platform',
        };
    }

    /**
     * Default channel choices (true = delivered).
     *
     * @return array<string, bool>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::Competition, self::Announcements => ['database' => true, 'mail' => false],
            default => ['database' => true, 'mail' => true],
        };
    }

    /**
     * Channels the user cannot switch off.
     *
     * @return list<string>
     */
    public function locked(): array
    {
        return match ($this) {
            self::Security => ['database', 'mail'],
            self::Prizes, self::Wallet => ['database'],
            default => [],
        };
    }
}
