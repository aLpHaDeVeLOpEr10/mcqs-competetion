<?php

/*
 * Request profiler for docs/PERFORMANCE.md. Drives real HTTP requests in-process (Laravel's
 * testing request helpers, full middleware stack) against the "_load" dataset built by
 * seed-profile.php, and reports for each page: median wall time, query count, SQL time and
 * repeated query shapes (likely N+1). It also profiles the live-play hot paths and the engine.
 *
 * SAFETY: same guard as the other load scripts ("_load" database, APP_ENV local/testing).
 * Usage: php tests/Load/profile.php [label] [--live=500]   (writes storage/app/profile-<label>.json)
 */

use App\Enums\QuizPaperStatus;
use App\Enums\RoomType;
use App\Models\CompetitionSession;
use App\Models\QuizPaper;
use App\Models\Room;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Engine\CompetitionEngine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$database = getenv('LOAD_DB') ?: 'mcq_competition_load';
foreach (['APP_ENV' => 'local', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
    'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'null', 'APP_DEBUG' => 'false'] as $key => $value) {
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

$label = $argv[1] ?? 'run';
$liveSize = 500;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--live=')) {
        $liveSize = (int) substr($arg, 7);
    }
}

final class Probe
{
    use InteractsWithAuthentication, MakesHttpRequests;

    public $app;

    /** @var list<array{sql: string, ms: float}> */
    public array $queries = [];

    public function __construct($app)
    {
        $this->app = $app;
        DB::listen(fn ($q) => $this->queries[] = ['sql' => $q->sql, 'ms' => $q->time, 'bindings' => $q->bindings]);
    }

    /**
     * @return array{status: int, wall_ms: float, queries: int, sql_ms: float, repeated: array<string, int>}
     */
    public function measure(?User $user, string $method, string $uri, array $data = [], int $runs = 5): array
    {
        $samples = [];
        for ($i = 0; $i <= $runs; $i++) {        // run 0 is a warm-up
            $this->app['auth']->forgetGuards();
            if ($user) {
                $this->actingAs($user);
            }
            $this->queries = [];
            $t = hrtime(true);
            $response = $this->call($method, $uri, $data);
            $wall = (hrtime(true) - $t) / 1e6;
            if ($i > 0) {
                $samples[] = ['wall' => $wall, 'queries' => $this->queries, 'status' => $response->getStatusCode()];
            }
        }
        usort($samples, fn ($a, $b) => $a['wall'] <=> $b['wall']);
        $median = $samples[intdiv(count($samples), 2)];

        $shapes = [];
        foreach ($median['queries'] as $q) {
            $shape = preg_replace('/\s+/', ' ', $q['sql']);
            $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
        }
        arsort($shapes);

        return [
            'status' => $median['status'],
            'wall_ms' => round($median['wall'], 1),
            'queries' => count($median['queries']),
            'sql_ms' => round(array_sum(array_column($median['queries'], 'ms')), 1),
            'repeated' => array_filter($shapes, fn ($n) => $n >= (getenv('PROFILE_REPEAT_MIN') ?: 4)),
            'slowest' => collect($median['queries'])->sortByDesc('ms')->take(2)->map(fn ($q) => ['ms' => round($q['ms'], 1), 'sql' => $q['sql'], 'bindings' => $q['bindings']])->values()->all(),
        ];
    }
}

$probe = new Probe($app);
$probe->withoutMiddleware([ValidateCsrfToken::class]);
$staff = User::query()->where('email', 'profile-staff@load.test')->firstOrFail();
$heavy = User::query()->whereHas('wallet', fn ($q) => $q->where('balance_minor', '>', 0))->orderBy('id')->firstOrFail();
$completedRoom = Room::query()->where('name', 'like', 'Profile competition%')->where('status', 'completed')->orderByDesc('id')->firstOrFail();

// A live room for the play hot paths.
$paper = QuizPaper::factory()->withQuestions(5)->create();
$paper->forceFill(['status' => QuizPaperStatus::Published])->save();
$live = Room::factory()->create([
    'name' => 'Profile live '.now()->format('His'), 'quiz_paper_id' => $paper->id, 'type' => RoomType::Fill,
    'scheduled_start_at' => null, 'registration_closes_at' => now()->addHour(),
    'min_participants' => $liveSize, 'max_participants' => $liveSize, 'countdown_seconds' => 0, 'question_gap_seconds' => 2, 'entry_fee_minor' => 0,
]);
app(RoomLifecycle::class)->publish($staff, $live);
$livePlayers = User::query()->where('role', 'player')->orderByDesc('id')->limit($liveSize)->get();
foreach ($livePlayers as $p) {
    app(RoomEntryService::class)->join($p, $live->fresh());
}
$session = CompetitionSession::query()->where('room_id', $live->id)->firstOrFail();
$engine = app(CompetitionEngine::class);
$engine->advance($session->id);
$engine->advance($session->id);
$question = $session->fresh()->currentQuestion();
$player = $livePlayers->first();
$option = $question->options()->value('id');

$cases = [
    // Public
    ['public', 'home', null, 'GET', '/'],
    ['public', 'competition listing', null, 'GET', '/competitions'],
    ['public', 'competition listing (filtered)', null, 'GET', '/competitions?status=open&entry=free'],
    ['public', 'competition details', null, 'GET', "/competitions/{$live->id}"],
    ['public', 'results (50 players)', null, 'GET', "/competitions/{$completedRoom->id}/results"],
    ['public', 'leaderboard', null, 'GET', '/leaderboard'],
    // Player
    ['player', 'account overview', $heavy, 'GET', '/account'],
    ['player', 'my competitions', $heavy, 'GET', '/account/competitions'],
    ['player', 'competition history', $heavy, 'GET', '/account/competitions/history'],
    ['player', 'wallet', $heavy, 'GET', '/account/wallet'],
    ['player', 'transactions (1,500+ entries)', $heavy, 'GET', '/account/wallet/transactions'],
    ['player', 'notifications', $heavy, 'GET', '/account/notifications'],
    // Live play
    ['play', "play page ({$liveSize}-player room)", $player, 'GET', "/play/{$live->id}"],
    ['play', 'state poll', $player, 'GET', "/play/{$live->id}/state"],
    // Admin
    ['admin', 'dashboard', $staff, 'GET', '/admin'],
    ['admin', 'rooms', $staff, 'GET', '/admin/rooms'],
    ['admin', 'users', $staff, 'GET', '/admin/users'],
    ['admin', 'results', $staff, 'GET', '/admin/results'],
    ['admin', 'wallets', $staff, 'GET', '/admin/wallets'],
    ['admin', 'ledger', $staff, 'GET', '/admin/ledger'],
    ['admin', 'payouts', $staff, 'GET', '/admin/payouts'],
    ['admin', 'audit', $staff, 'GET', '/admin/audit'],
    ['admin', 'reports overview (30 days)', $staff, 'GET', '/admin/reports'],
    ['admin', 'competition report', $staff, 'GET', '/admin/reports/competitions'],
    ['admin', 'finance: entry fees', $staff, 'GET', '/admin/reports/finance/entry_fees'],
    ['admin', 'finance: reconciliation', $staff, 'GET', '/admin/reports/finance/reconciliation'],
];

$results = [];
printf("%-7s %-36s %6s %9s %8s %8s\n", 'area', 'page', 'status', 'wall ms', 'queries', 'sql ms');
$only = getenv('PROFILE_ONLY');
foreach ($cases as [$area, $name, $user, $method, $uri]) {
    if ($only && ! str_contains($name, $only)) {
        continue;
    }
    $r = $probe->measure($user, $method, $uri);
    $results[] = ['area' => $area, 'name' => $name, 'uri' => $uri] + $r;
    printf("%-7s %-36s %6d %9.1f %8d %8.1f\n", $area, $name, $r['status'], $r['wall_ms'], $r['queries'], $r['sql_ms']);
    if (in_array('--slow', $argv, true) && $r['sql_ms'] >= 8) {
        foreach ($r['slowest'] as $q) {
            printf('        slow %.1f ms: %s | %s
', $q['ms'], mb_substr(preg_replace('/\s+/', ' ', $q['sql']), 0, 400), json_encode($q['bindings']));
        }
    }
    foreach (array_slice($r['repeated'], 0, 3, true) as $sql => $n) {
        printf("        repeated x%d: %s\n", $n, mb_substr($sql, 0, 150));
    }
}

// Answer submission: each request is a different player (first answer, the real hot path).
$answerSamples = [];
foreach ($livePlayers->slice(1, 60)->values() as $i => $p) {
    $probe->app['auth']->forgetGuards();
    $probe->actingAs($p);
    $probe->queries = [];
    $t = hrtime(true);
    $status = $probe->call('POST', "/play/{$live->id}/answers", ['question_id' => $question->id, 'option_id' => $option])->getStatusCode();
    $answerSamples[] = ['wall' => (hrtime(true) - $t) / 1e6, 'queries' => count($probe->queries), 'sql' => array_sum(array_column($probe->queries, 'ms')), 'status' => $status];
}
usort($answerSamples, fn ($a, $b) => $a['wall'] <=> $b['wall']);
$m = $answerSamples[intdiv(count($answerSamples), 2)];
$results[] = ['area' => 'play', 'name' => 'answer submission (HTTP)', 'uri' => "/play/{$live->id}/answers", 'status' => $m['status'], 'wall_ms' => round($m['wall'], 1), 'queries' => $m['queries'], 'sql_ms' => round($m['sql'], 1), 'repeated' => []];
printf("%-7s %-36s %6d %9.1f %8d %8.1f   (median of %d players)\n", 'play', 'answer submission (HTTP)', $m['status'], $m['wall'], $m['queries'], $m['sql'], count($answerSamples));

// Engine: close the question for the whole room.
Carbon::setTestNow($question->closes_at->copy()->addSeconds(2));
CarbonImmutable::setTestNow($question->closes_at->copy()->addSeconds(2));
$probe->queries = [];
$t = hrtime(true);
$engine->advance($session->id);
$results[] = ['area' => 'engine', 'name' => "close question ({$liveSize} players)", 'uri' => '', 'status' => 0, 'wall_ms' => round((hrtime(true) - $t) / 1e6, 1), 'queries' => count($probe->queries), 'sql_ms' => round(array_sum(array_column($probe->queries, 'ms')), 1), 'repeated' => []];
printf("%-7s %-36s %6s %9.1f %8d %8.1f\n", 'engine', "close question ({$liveSize} players)", '-', end($results)['wall_ms'], end($results)['queries'], end($results)['sql_ms']);

file_put_contents(storage_path("app/profile-{$label}.json"), json_encode(['label' => $label, 'at' => now()->toIso8601String(), 'results' => $results], JSON_PRETTY_PRINT));
echo "\nSaved storage/app/profile-{$label}.json\n";
