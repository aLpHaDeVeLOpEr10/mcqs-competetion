<?php

namespace Tests\Feature\Engine;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\CreatesLiveCompetition;
use Tests\TestCase;

/**
 * Several workers (queue jobs + scheduler ticks) advance the same competition
 * at the same moment. Exactly one of them may close the question and schedule
 * the next one; the others must see the committed state, not a stale snapshot.
 */
class ConcurrentAdvanceTest extends TestCase
{
    use CreatesLiveCompetition, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_concurrent_engine_runs_close_a_question_exactly_once(): void
    {
        $this->startingCompetition(2, ['countdown_seconds' => 0]);
        $this->travelBack();
        $this->advance(); // prepare
        $this->advance(); // start: question 1 delivered

        $startAt = microtime(true) + 4;
        // Make question 1 due to close exactly when the workers start.
        $q1 = $this->question(1);
        $closesAt = CarbonImmutable::createFromTimestamp($startAt - 1);
        $q1->forceFill(['opens_at' => $closesAt->subSeconds($q1->time_limit_seconds), 'closes_at' => $closesAt])->save();

        $processes = [];
        foreach (range(1, 6) as $i) {
            $command = [PHP_BINARY, base_path('tests/Support/concurrent-advance.php'), (string) $this->session->id, sprintf('%.6F', $startAt)];
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

        $this->assertSame([], array_values(array_filter(array_column($results, 'error'))), 'No worker may crash on a stale read.');

        $this->session->refresh();
        $q1 = $this->question(1);
        $q2 = $this->question(2);
        $this->assertSame(2, $this->session->current_question_position);
        $this->assertNotNull($q1->closed_at);
        $this->assertTrue($q2->opens_at->equalTo($q1->closed_at->copy()->addSeconds($this->room->question_gap_seconds)),
            'Question 2 was scheduled exactly once, from the single close.');
        $this->assertNull($q2->delivered_at);
    }
}
