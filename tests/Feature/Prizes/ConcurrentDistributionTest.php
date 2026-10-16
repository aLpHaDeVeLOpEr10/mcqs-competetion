<?php

namespace Tests\Feature\Prizes;

use App\Enums\LedgerTransactionType;
use App\Enums\PayoutStatus;
use App\Models\LedgerTransaction;
use App\Models\PrizePayout;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\PlaysCompetitions;
use Tests\TestCase;

/**
 * Several workers (finalizer, scheduler, staff) distribute the same prizes at
 * the same instant, in separate processes. Each winner is credited once.
 */
class ConcurrentDistributionTest extends TestCase
{
    use DatabaseTruncation, PlaysCompetitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_simultaneous_distributions_credit_each_winner_once(): void
    {
        config(['competition.prizes.payouts_enabled' => false]); // entitlements recorded, held
        $this->competitionWithPrizes(3, [[1, 1, 10_000], [2, 2, 5_000]]);
        $plan = [];
        foreach (range(1, 5) as $q) {
            $plan[$q] = [0 => [1000, true], 1 => [1500, $q <= 3], 2 => [500, false]];
        }
        $this->playOut($plan);
        $this->travelBack();
        $this->assertSame(0, LedgerTransaction::query()->where('type', LedgerTransactionType::PrizePayout)->count());

        $startAt = sprintf('%.6F', microtime(true) + 4);
        $processes = [];
        foreach (range(1, 6) as $i) {
            $command = [PHP_BINARY, base_path('tests/Support/concurrent-distribute.php'), (string) $this->session->id, $startAt];
            $processes[] = [proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path()), $pipes];
        }

        $paid = 0;
        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $decoded = json_decode(trim($out), true);
            $this->assertIsArray($decoded, "Child process output: {$out} {$err}");
            $this->assertArrayNotHasKey('error', $decoded, $decoded['error'] ?? '');
            $paid += $decoded['paid'];
        }

        $this->assertSame(2, $paid, 'Across all workers, exactly two payouts were made.');
        $this->assertSame(2, LedgerTransaction::query()->where('type', LedgerTransactionType::PrizePayout)->count());
        $this->assertSame(2, PrizePayout::query()->where('status', PayoutStatus::Paid)->count());
        $wallets = app(WalletService::class);
        $this->assertSame([10_000, 5_000, 0], $this->players->map(fn ($p) => $wallets->getAvailableBalance($p))->all());
        $this->assertSame([], app(WalletReconciler::class)->check());
    }
}
