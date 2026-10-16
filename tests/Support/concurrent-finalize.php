<?php

/*
 * Child process for ConcurrentFinalizeTest: waits for a shared instant, then
 * finalizes the session. Usage: php concurrent-finalize.php <sessionId> <startAtMicrotime>
 */

use App\Services\Results\ResultsFinalizer;
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
    $version = app(ResultsFinalizer::class)->finalize((int) $sessionId);
    echo json_encode(['version' => $version?->version]);
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e).': '.$e->getMessage()]);
}
