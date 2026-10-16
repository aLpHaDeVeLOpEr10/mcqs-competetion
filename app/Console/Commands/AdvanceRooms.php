<?php

namespace App\Console\Commands;

use App\Services\Competition\RoomScheduler;
use Illuminate\Console\Command;

class AdvanceRooms extends Command
{
    protected $signature = 'rooms:advance';

    protected $description = 'Expire abandoned reservations, open registration, and start or cancel rooms whose time has come';

    public function handle(RoomScheduler $scheduler): int
    {
        $summary = $scheduler->advance();

        $this->info(collect($summary)->map(fn ($count, $key) => str_replace('_', ' ', $key).": {$count}")->implode(', '));

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
