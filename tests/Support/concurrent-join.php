<?php

/*
 * Child process for ConcurrentJoinTest: boots the app against the test
 * database, waits until a shared start instant, then attempts one join.
 * Usage: php concurrent-join.php <roomId> <userId> <startAtMicrotime>
 * Prints one JSON line: {"outcome": ...} | {"denied": ...} | {"error": ...}
 */

use App\Exceptions\EntryDeniedException;
use App\Models\Room;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use Illuminate\Contracts\Console\Kernel;

foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mariadb',
    'DB_DATABASE' => 'mcq_competition_test',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'SESSION_DRIVER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
    // Paid rooms are debited from the wallet (free rooms are unaffected).
    'COMPETITION_PAID_ENTRIES_ENABLED' => 'true',
    // Test-only: the real-money launch gate needs a recorded approval (config/compliance.php).
    'COMPLIANCE_REAL_MONEY_APPROVED' => 'true', 'COMPLIANCE_APPROVAL_REFERENCE' => 'automated-tests',
    'COMPLIANCE_CONFIRMED_LEGAL_REVIEW' => 'true',
    'COMPLIANCE_CONFIRMED_PAYMENT_PROVIDER_APPROVAL' => 'true',
    'COMPLIANCE_CONFIRMED_AGE_VERIFICATION' => 'true',
    'COMPLIANCE_CONFIRMED_KYC' => 'true',
    'COMPLIANCE_CONFIRMED_AML' => 'true',
    'COMPLIANCE_CONFIRMED_GEO_RESTRICTION' => 'true',
    'COMPLIANCE_CONFIRMED_RESPONSIBLE_PLAY' => 'true',
    'COMPLIANCE_CONFIRMED_SELF_EXCLUSION' => 'true',
    'COMPLIANCE_CONFIRMED_TAX_REPORTING' => 'true',
    'COMPLIANCE_CONFIRMED_TERMS_RULES_REFUNDS' => 'true',
    'COMPLIANCE_CONFIRMED_PRIVACY_POLICY' => 'true',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $roomId, $userId, $startAt] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $result = app(RoomEntryService::class)->join(User::findOrFail($userId), Room::findOrFail($roomId));
    echo json_encode(['outcome' => $result['outcome']]);
} catch (EntryDeniedException $e) {
    echo json_encode(['denied' => $e->reason->value]);
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e).': '.$e->getMessage()]);
}
