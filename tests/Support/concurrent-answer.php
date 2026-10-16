<?php

/*
 * Child process for ConcurrentAnswerTest: waits for a shared instant, then
 * submits one answer through the real AnswerService.
 * Usage: php concurrent-answer.php <roomId> <userId> <questionId> <optionId> <startAtMicrotime>
 */

use App\Models\Room;
use App\Models\User;
use App\Services\Engine\AnswerRejectedException;
use App\Services\Engine\AnswerService;
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

[, $roomId, $userId, $questionId, $optionId, $startAt] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $result = app(AnswerService::class)->submit(User::findOrFail($userId), Room::findOrFail($roomId), (int) $questionId, (int) $optionId);
    echo json_encode(['status' => $result['status']]);
} catch (AnswerRejectedException $e) {
    echo json_encode(['rejected' => $e->reason]);
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e).': '.$e->getMessage()]);
}
