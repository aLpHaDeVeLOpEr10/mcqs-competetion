<?php

namespace Tests\Feature\Withdrawals;

use App\Models\Withdrawal;
use App\Services\Wallet\WalletReconciler;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Tests\Support\UsesWithdrawals;
use Tests\TestCase;

/**
 * Simultaneous withdrawal requests from separate processes: the held funds
 * can never exceed the balance, and a repeated request is created once.
 */
class ConcurrentWithdrawalTest extends TestCase
{
    use DatabaseTruncation, UsesWithdrawals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array<string, mixed>>
     */
    protected function race(int $userId, int $destinationId, int $amount, array $tokens): array
    {
        $startAt = sprintf('%.6F', microtime(true) + 4);
        $processes = [];
        foreach ($tokens as $token) {
            $command = [PHP_BINARY, base_path('tests/Support/concurrent-withdraw.php'), (string) $userId, (string) $destinationId, (string) $amount, $token, $startAt];
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

    public function test_simultaneous_requests_cannot_hold_more_than_the_balance(): void
    {
        $this->enableWithdrawals();
        $user = $this->funded(250_000);
        $destination = $this->destination($user);

        // Six requests of Rs 1,000 against Rs 2,500: only two fit.
        $results = collect($this->race($user->id, $destination->id, 100_000, array_map(fn () => (string) Str::uuid(), range(1, 6))));

        $this->assertSame(2, $results->whereNotNull('withdrawal')->count());
        $this->assertSame(4, $results->where('refused', 'insufficient_funds')->count());
        $this->assertSame(2, Withdrawal::query()->count());
        $this->assertSame(50_000, $this->balance($user));
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_a_repeated_request_is_created_once(): void
    {
        $this->enableWithdrawals();
        $user = $this->funded(500_000);
        $destination = $this->destination($user);

        $results = collect($this->race($user->id, $destination->id, 100_000, array_fill(0, 5, (string) Str::uuid())));

        $this->assertCount(1, $results->pluck('withdrawal')->unique());
        $this->assertSame(1, $results->where('created', true)->count());
        $this->assertSame(1, Withdrawal::query()->count());
        $this->assertSame(400_000, $this->balance($user));
    }
}
