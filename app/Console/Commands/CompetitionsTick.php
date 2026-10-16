<?php

namespace App\Console\Commands;

use App\Enums\RoomStatus;
use App\Enums\SessionStatus;
use App\Models\CompetitionSession;
use App\Services\Competition\RoomScheduler;
use App\Services\Engine\CompetitionEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Recovery heartbeat, scheduled every five seconds: applies due room
 * transitions and advances every live session. Delayed queue jobs give
 * precise timing; this guarantees progress if workers were down, a job was
 * lost, or the application restarted. Safe to run concurrently with jobs.
 */
class CompetitionsTick extends Command
{
    protected $signature = 'competitions:tick';

    protected $description = 'Advance rooms and live competition sessions that are due (recovery heartbeat)';

    public function handle(RoomScheduler $rooms, CompetitionEngine $engine): int
    {
        // Heartbeat for ops:status ("is the scheduler running?").
        Cache::forever(OpsStatus::TICK_HEARTBEAT, now()->toIso8601String());

        $roomSummary = $rooms->advance();
        $advanced = 0;
        $errors = $roomSummary['errors'];

        $sessions = CompetitionSession::query()
            ->whereIn('status', [SessionStatus::Countdown, SessionStatus::InProgress, SessionStatus::Scoring])
            ->orWhere(fn ($q) => $q->where('status', SessionStatus::Pending)
                ->whereHas('room', fn ($r) => $r->where('status', RoomStatus::Starting)))
            ->pluck('id');

        foreach ($sessions as $sessionId) {
            try {
                $engine->advance($sessionId);
                $advanced++;
            } catch (Throwable $e) {
                report($e);
                $errors++;
            }
        }

        $this->info("rooms started: {$roomSummary['started']}, rooms cancelled: {$roomSummary['cancelled']}, sessions advanced: {$advanced}, errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
