<?php

/*
 * Child process for ConcurrentDebitTest: waits for a shared instant, then
 * debits a wallet. Usage: php concurrent-debit.php <walletId> <amountMinor> <idempotencyKey> <startAtMicrotime>
 */

use App\Enums\LedgerTransactionType;
use App\Exceptions\WalletOperationException;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
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

[, $walletId, $amount, $idempotencyKey, $startAt] = $argv;
$wallet = Wallet::query()->findOrFail((int) $walletId);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

try {
    $tx = app(WalletService::class)->debit($wallet, (int) $amount, LedgerTransactionType::EntryFee, $idempotencyKey);
    echo json_encode(['transaction' => $tx->id, 'created' => $tx->wasRecentlyCreated]);
} catch (WalletOperationException $e) {
    echo json_encode(['refused' => $e->reason]);
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e).': '.$e->getMessage()]);
}
