<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Start policy of a room.
 */
enum RoomType: string
{
    use HasValues;

    case Scheduled = 'scheduled';
    case Fill = 'fill';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled start',
            self::Fill => 'Starts when full',
            self::Hybrid => 'Starts when full or at the scheduled time',
        };
    }

    public function startsWhenFull(): bool
    {
        return $this !== self::Scheduled;
    }

    public function hasScheduledStart(): bool
    {
        return $this !== self::Fill;
    }
}
