<?php

/*
 * Seeds a realistic dataset for profiling (docs/PERFORMANCE.md), through the REAL services so
 * every record is consistent: paid competitions are joined, played (answers via AnswerService),
 * finalized and paid out by the engine, spread over the last 30 days.
 *
 * SAFETY: same guard as answer-burst.php (database name must end in "_load", APP_ENV local).
 * Usage: php tests/Load/seed-profile.php <completed=40> <players=50> <listed=80>
 */

use App\Enums\LedgerTransactionType;
use App\Enums\QuizPaperStatus;
use App\Enums\RoomType;
use App\Enums\UserRole;
use App\Models\CompetitionSession;
use App\Models\QuizPaper;
use App\Models\Room;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use App\Services\Engine\AnswerRejectedException;
use App\Services\Engine\AnswerService;
use App\Services\Engine\CompetitionEngine;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

$database = getenv('LOAD_DB') ?: 'mcq_competition_load';
foreach (['APP_ENV' => 'local', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
    'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'null'] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_ends_with((string) config('database.connections.'.config('database.default').'.database'), '_load') || ! app()->environment('local')) {
    fwrite(STDERR, "Refusing to run: the database name must end in _load and APP_ENV must be local.\n");
    exit(2);
}

config(['competition.paid_entries.enabled' => true, 'competition.prizes.payouts_enabled' => true, 'competition.results.auto_publish' => true]);

$completed = (int) ($argv[1] ?? 40);
$perRoom = (int) ($argv[2] ?? 50);
$listed = (int) ($argv[3] ?? 80);
$t0 = microtime(true);
$say = fn (string $m) => fwrite(STDOUT, sprintf("[%6.1fs] %s\n", microtime(true) - $t0, $m));

$staff = User::query()->where('email', 'profile-staff@load.test')->first()
    ?? User::factory()->role(UserRole::SuperAdmin)->create(['email' => 'profile-staff@load.test', 'display_name' => 'profilestaff']);
$wallets = app(WalletService::class);
$engine = app(CompetitionEngine::class);
$answers = app(AnswerService::class);
$tag = now()->format('His');

$paper = function () {
    $paper = QuizPaper::factory()->withQuestions(5)->create();
    $paper->forceFill(['status' => QuizPaperStatus::Published])->save();

    return $paper;
};

// Players: reuse existing load users, funding each wallet once.
$pool = User::query()->where('role', 'player')->orderBy('id')->limit(max($perRoom * 4, 400))->get();
foreach ($pool as $user) {
    $wallets->credit($wallets->walletFor($user), 500_000, LedgerTransactionType::Deposit, 'profile-seed:'.$user->id);
}
$say("funded {$pool->count()} players");

for ($c = 0; $c < $completed; $c++) {
    $base = CarbonImmutable::now()->subDays(30)->addHours((int) round($c * 30 * 24 / max(1, $completed)))->startOfSecond();
    CarbonImmutable::setTestNow($base);
    Carbon::setTestNow($base);

    $room = Room::factory()->create([
        'name' => "Profile competition {$tag}-{$c}", 'quiz_paper_id' => $paper()->id, 'type' => RoomType::Fill,
        'scheduled_start_at' => null, 'registration_closes_at' => $base->addDay(),
        'min_participants' => $perRoom, 'max_participants' => $perRoom, 'countdown_seconds' => 5, 'question_gap_seconds' => 3,
        'entry_fee_minor' => 10_000,
    ]);
    $room->prizeTiers()->create(['rank_from' => 1, 'rank_to' => 1, 'amount_minor' => 50_000]);
    $room->prizeTiers()->create(['rank_from' => 2, 'rank_to' => 3, 'amount_minor' => 20_000]);
    app(RoomLifecycle::class)->publish($staff, $room);

    $players = $pool->shuffle()->take($perRoom)->values();
    foreach ($players as $p) {
        app(RoomEntryService::class)->join($p, $room->fresh());
    }

    $session = CompetitionSession::query()->where('room_id', $room->id)->firstOrFail();
    $engine->advance($session->id);
    $now = CarbonImmutable::instance($session->fresh()->starts_at);
    for ($position = 1; $position <= 5; $position++) {
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);
        $engine->advance($session->id);
        $question = $session->fresh()->currentQuestion();
        if ($question === null || $question->delivered_at === null) {
            break;
        }
        $options = $question->options()->get(['id', 'is_correct']);
        foreach ($players as $i => $p) {
            if (($i + $position) % 7 === 0) {
                continue;   // some players miss a question
            }
            $at = CarbonImmutable::instance($question->opens_at)->addMilliseconds(500 + ($i * 137 + $position * 59) % 9000);
            CarbonImmutable::setTestNow($at);
            Carbon::setTestNow($at);
            $correct = ($i * 3 + $position) % 4 !== 0;
            try {
                $answers->submit($p, $room, $question->id, $options->firstWhere('is_correct', $correct)->id);
            } catch (AnswerRejectedException) {
            }
        }
        $now = CarbonImmutable::instance($question->closes_at)->addSecond();
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);
        $engine->advance($session->id);
        $now = CarbonImmutable::instance($session->fresh()->currentQuestion()?->opens_at ?? $now);
    }
    $say("competition {$c}: room #{$room->id} ".$session->fresh()->status->value);
}

CarbonImmutable::setTestNow();
Carbon::setTestNow();

// Open / upcoming rooms for the public listing.
for ($i = 0; $i < $listed; $i++) {
    $room = Room::factory()->create([
        'name' => "Upcoming quiz {$tag}-{$i}", 'quiz_paper_id' => $paper()->id, 'type' => $i % 2 ? RoomType::Scheduled : RoomType::Fill,
        'scheduled_start_at' => $i % 2 ? now()->addDays(1 + $i % 10) : null, 'registration_closes_at' => now()->addDays(1 + $i % 10),
        'min_participants' => 2, 'max_participants' => 100, 'entry_fee_minor' => $i % 3 ? 0 : 5_000,
    ]);
    app(RoomLifecycle::class)->publish($staff, $room);
    foreach ($pool->shuffle()->take($i % 20) as $p) {
        app(RoomEntryService::class)->join($p, $room->fresh());
    }
}
$say("{$listed} open/upcoming rooms");

// One heavy player: a long transaction history.
$heavy = $pool->first();
for ($i = 0; $i < 1500; $i++) {
    $wallets->credit($wallets->walletFor($heavy), 100, LedgerTransactionType::Deposit, "profile-heavy:{$tag}:{$i}");
}
$say("heavy player #{$heavy->id} ({$heavy->email}) has a long ledger history");

// The question factory creates one category per question; a real catalogue has tens. Map every
// question onto 12 categories and deactivate the rest, so listing filters are realistic.
$first = (int) DB::table('categories')->min('id');
DB::statement('UPDATE questions SET category_id = ? + MOD(id, 12)', [$first]);
DB::table('categories')->where('id', '>=', $first + 12)->update(['is_active' => false]);
$say('categories normalized to 12 active');
