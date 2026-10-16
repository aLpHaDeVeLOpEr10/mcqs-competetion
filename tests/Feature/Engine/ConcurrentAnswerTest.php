<?php

namespace Tests\Feature\Engine;

use App\Models\AnswerSubmission;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\CreatesLiveCompetition;
use Tests\TestCase;

/**
 * Real concurrency: separate PHP processes submit answers at the same
 * instant. Uses the real clock (no time travel), so the competition starts
 * immediately and the question window is open while the processes run.
 */
class ConcurrentAnswerTest extends TestCase
{
    use CreatesLiveCompetition, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_simultaneous_answers_from_many_players_and_duplicate_tabs(): void
    {
        $this->startingCompetition(8, ['countdown_seconds' => 0]);
        $this->travelBack(); // real clock from here on
        $this->advance();    // prepare
        $this->advance();    // start: question 1 opens now, for 20 seconds

        $question = $this->question(1);
        $this->assertNotNull($question->delivered_at);
        $options = $question->options()->pluck('id')->all();

        // Player 0 fires 6 requests at once (3 tabs × 2 different options); players 1–7 answer once each.
        $jobs = [];
        foreach (range(0, 5) as $i) {
            $jobs[] = [$this->players[0]->id, $options[$i % 2]];
        }
        foreach ($this->players->slice(1) as $player) {
            $jobs[] = [$player->id, $options[0]];
        }

        $results = collect($this->race($question->id, $jobs));

        $this->assertSame([], $results->pluck('error')->filter()->values()->all(), 'No process may crash or deadlock.');
        $this->assertSame(8, AnswerSubmission::count(), 'Exactly one answer per player.');
        $this->assertSame(1, AnswerSubmission::whereHas('participant', fn ($q) => $q->where('user_id', $this->players[0]->id))->count());

        $player0 = $results->take(6);
        $this->assertSame(1, $player0->where('status', 'accepted')->count());
        $this->assertSame(6, $player0->filter(fn ($r) => in_array($r['status'] ?? $r['rejected'] ?? null, ['accepted', 'duplicate', 'already_answered'], true))->count());
        $this->assertSame(7, $results->slice(6)->where('status', 'accepted')->count());
    }

    /**
     * @param  list<array{0: int, 1: int}>  $jobs  [userId, optionId]
     * @return list<array<string, string>>
     */
    protected function race(int $questionId, array $jobs): array
    {
        $startAt = sprintf('%.6F', microtime(true) + 4);
        $processes = [];

        foreach ($jobs as [$userId, $optionId]) {
            $command = [PHP_BINARY, base_path('tests/Support/concurrent-answer.php'), (string) $this->room->id, (string) $userId, (string) $questionId, (string) $optionId, $startAt];
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

        return $results;
    }
}
