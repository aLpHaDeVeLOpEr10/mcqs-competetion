<?php

namespace Tests\Feature\Payments;

use App\Enums\LedgerTransactionType;
use App\Enums\PaymentStatus;
use App\Models\LedgerTransaction;
use App\Models\PaymentCallback;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\UsesFakeGateway;
use Tests\TestCase;

/**
 * A provider delivers the same success notification several times at once
 * (separate processes, separate DB connections). The wallet is credited once.
 */
class ConcurrentCallbackTest extends TestCase
{
    use DatabaseTruncation, UsesFakeGateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_simultaneous_duplicate_callbacks_credit_once(): void
    {
        $this->enableFakeGateway('database');
        $player = User::factory()->create();
        app(WalletService::class)->systemWallet(Wallet::SYSTEM_GATEWAY_CLEARING, 'PKR');
        $payment = $this->startDeposit($player, 120_000);
        $notification = json_encode($this->fake()->complete($payment->gateway_reference, 'succeeded'));

        $startAt = sprintf('%.6F', microtime(true) + 4);
        $processes = [];
        foreach (range(1, 6) as $i) {
            $command = [PHP_BINARY, base_path('tests/Support/concurrent-callback.php'), $notification, self::FAKE_SECRET, $startAt];
            $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path()), $pipes];
        }

        $outcomes = [];
        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $decoded = json_decode(trim($out), true);
            $this->assertIsArray($decoded, "Child process output: {$out} {$err}");
            $this->assertArrayNotHasKey('error', $decoded, $decoded['error'] ?? '');
            $outcomes[] = $decoded['outcome'];
        }

        sort($outcomes);
        $this->assertSame([PaymentCallback::APPLIED, ...array_fill(0, 5, PaymentCallback::DUPLICATE)], $outcomes);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(120_000, app(WalletService::class)->getAvailableBalance($player));
        $this->assertSame(1, LedgerTransaction::query()->where('type', LedgerTransactionType::Deposit)->count());
        $this->assertSame(6, PaymentCallback::query()->count());
        $this->assertSame([], app(WalletReconciler::class)->check());
    }
}
