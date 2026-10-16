<?php

/*
 * Child process for ConcurrentAdvanceTest: waits for a shared instant, then
 * runs the engine once. Usage: php concurrent-advance.php <sessionId> <startAtMicrotime>
 */

use App\Services\Engine\CompetitionEngine;
use Illuminate\Contracts\Console\Kernel;

foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mariadb', 'DB_DATABASE' => 'mcq_competition_test',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $sessionId, $startAt] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    app(CompetitionEngine::class)->advance((int) $sessionId);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e).': '.$e->getMessage()]);
}
