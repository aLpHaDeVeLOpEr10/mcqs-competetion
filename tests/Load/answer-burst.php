<?php

/*
 * Local/staging load simulation: N players answer the same live question at the
 * same instant from W parallel PHP processes, through the real AnswerService and
 * database (no HTTP, no external services).
 *
 * SAFETY: runs only against a database whose name ends in "_load", with APP_ENV
 * local or testing. It creates its own users, paper and room in that database and
 * never deletes anything. Never point it at production.
 *
 * Setup once (local MariaDB):
 *   mysql -u root -e "CREATE DATABASE IF NOT EXISTS mcq_competition_load"
 *   DB_DATABASE=mcq_competition_load php artisan migrate --force
 * Run:
 *   php tests/Load/answer-burst.php run <players=200> <workers=8>
 * See docs/LOAD_TESTING.md.
 */

use App\Enums\QuizPaperStatus;
use App\Enums\RoomType;
use App\Enums\UserRole;
use App\Exceptions\ContentValidationException;
use App\Models\AnswerSubmission;
use App\Models\CompetitionSession;
use App\Models\QuizPaper;
use App\Models\Room;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Engine\AnswerRejectedException;
use App\Services\Engine\AnswerService;
use App\Services\Engine\CompetitionEngine;
use Illuminate\Contracts\Console\Kernel;

$database = getenv('LOAD_DB') ?: 'mcq_competition_load';
$env = [
    'APP_ENV' => 'local', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
    'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'null',
];
foreach ($env as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_ends_with((string) config('database.connections.'.config('database.default').'.database'), '_load') || ! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "Refusing to run: the database name must end in _load and APP_ENV must be local or testing.\n");
    exit(2);
}

$mode = $argv[1] ?? 'run';

if ($mode === 'worker') {
    // php answer-burst.php worker <roomId> <questionId> <optionIdsCsv> <startAt> <userIdsCsv>
    [, , $roomId, $questionId, $optionCsv, $startAt, $userCsv] = $argv;
    $room = Room::query()->findOrFail($roomId);
    $users = User::query()->whereIn('id', explode(',', $userCsv))->get();
    $options = array_map('intval', explode(',', $optionCsv));
    $service = app(AnswerService::class);
    $out = ['latencies_ms' => [], 'accepted' => 0, 'duplicate' => 0, 'rejected' => [], 'errors' => []];

    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }

    foreach ($users as $i => $user) {
        $option = $options[$user->id % count($options)];
        foreach ($i % 10 === 0 ? [1, 2] : [1] as $attempt) {   // every 10th player double-submits
            $t = hrtime(true);
            try {
                $result = $service->submit($user, $room, (int) $questionId, $option);
                $out[$result['status'] === AnswerService::ACCEPTED ? 'accepted' : 'duplicate']++;
            } catch (AnswerRejectedException $e) {
                $out['rejected'][$e->reason] = ($out['rejected'][$e->reason] ?? 0) + 1;
            } catch (Throwable $e) {
                $out['errors'][] = $e::class.': '.mb_substr($e->getMessage(), 0, 160);
            }
            $out['latencies_ms'][] = round((hrtime(true) - $t) / 1e6, 2);
        }
    }

    echo json_encode($out);
    exit(0);
}

// ---------------------------------------------------------------- parent
$players = max(2, (int) ($argv[2] ?? 200));
$workers = max(1, min(32, (int) ($argv[3] ?? 8)));
$started = microtime(true);
$say = fn (string $m) => fwrite(STDOUT, sprintf("[%6.2fs] %s\n", microtime(true) - $started, $m));

$staff = User::factory()->role(UserRole::CompetitionManager)->create();
$paper = QuizPaper::factory()->withQuestions(5)->create();
$paper->forceFill(['status' => QuizPaperStatus::Published])->save();
$room = Room::factory()->create([
    'name' => 'Load burst '.now()->format('His'), 'quiz_paper_id' => $paper->id, 'type' => RoomType::Fill,
    'scheduled_start_at' => null, 'registration_closes_at' => now()->addHour(),
    'min_participants' => $players, 'max_participants' => $players, 'countdown_seconds' => 2, 'question_gap_seconds' => 2, 'entry_fee_minor' => 0,
]);
try {
    app(RoomLifecycle::class)->publish($staff, $room);
} catch (ContentValidationException $e) {
    fwrite(STDERR, 'Room could not be published: '.json_encode($e->problems).'
');
    exit(1);
}

// Deterministic unique names: random factory names collide at this volume (display_name is unique).
$tag = now()->format('His');
$users = User::factory()->count($players)->sequence(fn ($s) => [
    'display_name' => "load{$tag}p{$s->index}",
    'email' => "load{$tag}p{$s->index}@load.test",
])->create();
$joinStart = microtime(true);
foreach ($users as $user) {
    app(RoomEntryService::class)->join($user, $room->fresh());
}
$say(sprintf('%d players joined room #%d in %.2fs (%.1f ms/join)', $players, $room->id, microtime(true) - $joinStart, (microtime(true) - $joinStart) * 1000 / $players));

$session = CompetitionSession::query()->where('room_id', $room->id)->firstOrFail();
$engine = app(CompetitionEngine::class);
$t = microtime(true);
$engine->advance($session->id);                                  // prepare → countdown
$say(sprintf('engine prepare step took %.0f ms; starts at %s (room countdown %ds)', (microtime(true) - $t) * 1000, $session->fresh()->starts_at?->format('H:i:s.v'), $room->countdown_seconds));
$waits = 0;
$waitStart = microtime(true);
while ($session->fresh()->starts_at?->isFuture() ?? true) {
    usleep(100_000);
    $engine->advance($session->id);
    $waits++;
}
$say(sprintf('countdown wait: %d engine runs in %.2fs (%.1f ms each incl. 100 ms sleep)', $waits, microtime(true) - $waitStart, (microtime(true) - $waitStart) * 1000 / max(1, $waits)));
$t = microtime(true);
$engine->advance($session->id);                                  // start → question 1 delivered
$say(sprintf('engine start step (seats → playing, question 1 delivered, start notices) took %.0f ms', (microtime(true) - $t) * 1000));
$question = $session->fresh()->currentQuestion();
$say("question 1 delivered; window {$question->time_limit_seconds}s, closes ".$question->closes_at->format('H:i:s.v'));

$optionIds = $question->options()->pluck('id')->implode(',');
$startAt = sprintf('%.6F', microtime(true) + 3);
$processes = [];
foreach ($users->pluck('id')->chunk((int) ceil($players / $workers)) as $chunk) {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $room->id, (string) $question->id, $optionIds, $startAt, $chunk->implode(',')],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env + getenv());
    $processes[] = [$process, $pipes];
}

$latencies = [];
$totals = ['accepted' => 0, 'duplicate' => 0, 'rejected' => [], 'errors' => []];
foreach ($processes as [$process, $pipes]) {
    $raw = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $part = json_decode(trim($raw), true);
    if (! is_array($part)) {
        $totals['errors'][] = 'worker output: '.mb_substr($raw.$err, 0, 300);

        continue;
    }
    $latencies = array_merge($latencies, $part['latencies_ms']);
    $totals['accepted'] += $part['accepted'];
    $totals['duplicate'] += $part['duplicate'];
    foreach ($part['rejected'] as $reason => $n) {
        $totals['rejected'][$reason] = ($totals['rejected'][$reason] ?? 0) + $n;
    }
    $totals['errors'] = array_merge($totals['errors'], $part['errors']);
}
$burst = microtime(true) - (float) $startAt;

sort($latencies);
$pct = fn (float $p) => $latencies === [] ? 0 : $latencies[(int) min(count($latencies) - 1, floor($p * count($latencies)))];
$stored = AnswerSubmission::query()->where('session_question_id', $question->id)->count();
$distinctSeats = AnswerSubmission::query()->where('session_question_id', $question->id)->distinct()->count('room_participant_id');

$say(sprintf('burst finished in %.2fs: %d requests from %d processes', $burst, count($latencies), count($processes)));
$say(sprintf('latency ms: p50 %.1f | p95 %.1f | p99 %.1f | max %.1f', $pct(0.50), $pct(0.95), $pct(0.99), $pct(1.0)));
$say(sprintf('accepted %d, idempotent duplicates %d, rejected %s, errors %d', $totals['accepted'], $totals['duplicate'], json_encode($totals['rejected']), count($totals['errors'])));
$say("stored answers: {$stored} (distinct seats {$distinctSeats}) for {$players} players");

// Close the question under the same data volume and time it.
$closeAt = $question->closes_at->copy()->addMilliseconds((int) config('competition.engine.answer_grace_ms'));
while ($closeAt->isFuture()) {
    usleep(100_000);
}
$t = microtime(true);
$engine->advance($session->id);
$say(sprintf('engine closed question 1 in %.1f ms (closed_at set: %s)', (microtime(true) - $t) * 1000, $question->fresh()->closed_at ? 'yes' : 'NO'));

foreach (array_slice($totals['errors'], 0, 5) as $error) {
    $say('error: '.$error);
}

$ok = $stored === $players && $distinctSeats === $players && $totals['accepted'] === $players && $totals['errors'] === [];
$say($ok ? 'PASS: every player has exactly one answer; duplicates were idempotent.' : 'FAIL: see the numbers above.');
exit($ok ? 0 : 1);
