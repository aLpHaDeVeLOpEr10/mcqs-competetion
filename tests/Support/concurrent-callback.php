<?php

/*
 * Child process for ConcurrentCallbackTest: waits for a shared instant, then
 * delivers a provider notification. Usage:
 *   php concurrent-callback.php <notificationJson> <secret> <startAtMicrotime>
 */

use App\Services\Payments\DepositService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mariadb', 'DB_DATABASE' => 'mcq_competition_test',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    // The simulated provider's state is shared with the parent through the database cache.
    'PAYMENT_GATEWAY' => 'fake', 'DEPOSITS_ENABLED' => 'true', 'FAKE_GATEWAY_ENABLED' => 'true',
    'FAKE_GATEWAY_SECRET' => $argv[2], 'FAKE_GATEWAY_MERCHANT_ID' => 'FAKE-MERCHANT', 'FAKE_GATEWAY_CACHE_STORE' => 'database',
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

[, $notification, , $startAt] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $outcome = app(DepositService::class)->handleCallback('fake', Request::create('/payments/callback/fake', 'POST', json_decode($notification, true)));
    echo json_encode(['outcome' => $outcome]);
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e).': '.$e->getMessage()]);
}
