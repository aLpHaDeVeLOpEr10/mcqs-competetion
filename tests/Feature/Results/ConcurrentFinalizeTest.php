<?php

namespace Tests\Feature\Results;

use App\Enums\ParticipantStatus;
use App\Enums\SessionStatus;
use App\Models\AuditLog;
use App\Models\ParticipantResult;
use App\Models\PrizePayout;
use App\Models\ResultVersion;
use App\Models\RoomParticipant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\PlaysCompetitions;
use Tests\TestCase;

/**
 * Several workers finalize the same competition at the same instant (separate
 * processes and DB connections). Exactly one result version may be created.
 */
class ConcurrentFinalizeTest extends TestCase
{
    use DatabaseTruncation, PlaysCompetitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_concurrent_finalizers_produce_one_result(): void
    {
        $this->competitionWithPrizes(3, [[1, 1, 10_000]]);

        // Play every question except the end, then put the session where the engine leaves it.
        $this->playOut([1 => [0 => [1000, true], 1 => [2000, true]]]);
        $this->assertSame(SessionStatus::Completed, $this->session->status, 'Sanity: the normal path finalizes.');

        // Fresh competition left in "scoring" without finalization, for the race.
        $this->competitionWithPrizes(3, [[1, 1, 10_000]]);
        foreach (range(1, 5) as $position) {
            $question = $this->question($position);
            $question->forceFill(['delivered_at' => $question->delivered_at ?? $question->opens_at ?? now(), 'opens_at' => $question->opens_at ?? now(),
                'closes_at' => $question->closes_at ?? now()->addSeconds(20), 'closed_at' => now()])->save();
        }
        RoomParticipant::query()->where('room_id', $this->room->id)->update(['status' => ParticipantStatus::Finished->value]);
        $this->session->forceFill(['status' => SessionStatus::Scoring, 'ended_at' => now()])->save();
        $this->travelBack();

        $startAt = sprintf('%.6F', microtime(true) + 4);
        $processes = [];
        foreach (range(1, 6) as $i) {
            $command = [PHP_BINARY, base_path('tests/Support/concurrent-finalize.php'), (string) $this->session->id, $startAt];
            $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path()), $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $decoded = json_decode(trim($out), true);
            $this->assertIsArray($decoded, "Child process output: {$out} {$err}");
            $results[] = $decoded;
        }

        $this->assertSame([], array_values(array_filter(array_column($results, 'error'))), 'No conflicts, crashes or deadlocks.');
        $this->assertSame(array_fill(0, 6, 1), array_column($results, 'version'), 'Every worker sees the same single result.');
        $this->assertSame(1, ResultVersion::where('competition_session_id', $this->session->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'results.finalized')->where('auditable_id', $this->session->id)->count());
        $this->assertSame(3, ParticipantResult::where('competition_session_id', $this->session->id)->count());
        $this->assertSame(SessionStatus::Completed, $this->session->fresh()->status);
        $this->assertLessThanOrEqual(1, PrizePayout::query()->whereHas('distribution', fn ($q) => $q->where('competition_session_id', $this->session->id))->count());
    }
}
