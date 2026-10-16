<?php

namespace Tests\Feature\Wallet;

use App\Enums\LedgerTransactionType;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Simultaneous debits from separate processes (separate DB connections),
 * released at the same instant. No double spending, no duplicate entries.
 */
class ConcurrentDebitTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    protected function fundedWallet(int $balance): Wallet
    {
        $wallets = app(WalletService::class);
        $wallet = $wallets->walletFor(User::factory()->create());
        $wallets->credit($wallet, $balance, LedgerTransactionType::Deposit, 'seed:'.$wallet->id);
        // Created up front so the workers only race on the debit itself.
        $wallets->systemWallet(Wallet::SYSTEM_PRIZE_POOL, 'PKR');

        return $wallet;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    protected function race(Wallet $wallet, int $amount, array $keys): array
    {
        $startAt = sprintf('%.6F', microtime(true) + 4);
        $processes = [];
        foreach ($keys as $key) {
            $command = [PHP_BINARY, base_path('tests/Support/concurrent-debit.php'), (string) $wallet->id, (string) $amount, $key, $startAt];
            $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path()), $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $decoded = json_decode(trim($out), true);
            $this->assertIsArray($decoded, "Child process output: {$out} {$err}");
            $this->assertArrayNotHasKey('error', $decoded, $decoded['error'] ?? '');
            $results[] = $decoded;
        }

        return $results;
    }

    public function test_simultaneous_debits_cannot_overspend(): void
    {
        $wallet = $this->fundedWallet(1_000);

        $results = $this->race($wallet, 300, array_map(fn ($i) => "race:{$i}", range(1, 8)));

        $succeeded = array_filter($results, fn ($r) => isset($r['transaction']));
        $refused = array_column($results, 'refused');
        $this->assertCount(3, $succeeded, 'Only three 300-paisa debits fit in 1,000.');
        $this->assertSame(array_fill(0, 5, 'insufficient_funds'), $refused);

        $this->assertSame(100, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(100, (int) LedgerEntry::query()->where('wallet_id', $wallet->id)->sum('amount_minor'));
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_simultaneous_retries_with_one_key_debit_once(): void
    {
        $wallet = $this->fundedWallet(1_000);

        $results = $this->race($wallet, 300, array_fill(0, 6, 'entry:seat-42'));

        $this->assertCount(1, array_unique(array_column($results, 'transaction')), 'Every retry returns the same transaction.');
        $this->assertCount(6, array_column($results, 'transaction'));
        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['created'])), 'Exactly one request created it.');
        $this->assertSame(1, LedgerTransaction::query()->where('idempotency_key', 'entry:seat-42')->count());
        $this->assertSame(700, (int) $wallet->fresh()->balance_minor);
        $this->assertSame([], app(WalletReconciler::class)->check());
    }
}
