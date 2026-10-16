<?php

namespace Tests\Feature\Rooms;

use App\Enums\LedgerTransactionType;
use App\Enums\ParticipantStatus;
use App\Enums\RoomStatus;
use App\Models\LedgerTransaction;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Wallet\WalletReconciler;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Collection;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

/**
 * Real concurrency: separate PHP processes (separate DB connections) race to
 * join the same room at the same instant. Data must be committed so the
 * child processes can see it, hence truncation instead of transactions.
 */
class ConcurrentJoinTest extends TestCase
{
    use CreatesRooms, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        // Truncation normally runs before each test; also clean up afterwards so the
        // committed race data never leaks into transaction-based tests that run next.
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    /**
     * @param  list<int>  $userIds
     * @return list<array<string, string>>
     */
    protected function raceToJoin(Room $room, array $userIds): array
    {
        $script = base_path('tests/Support/concurrent-join.php');
        $startAt = sprintf('%.6F', microtime(true) + 4); // time for every process to boot
        $processes = [];

        foreach ($userIds as $userId) {
            $command = [PHP_BINARY, $script, (string) $room->id, (string) $userId, $startAt];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
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
            $results[] = $decoded;
        }

        return $results;
    }

    public function test_simultaneous_joins_never_exceed_capacity(): void
    {
        $room = $this->openRoom(['min_participants' => 1, 'max_participants' => 5]);
        $users = User::factory()->count(14)->create();

        $results = collect($this->raceToJoin($room, $users->pluck('id')->all()));

        $this->assertSame([], $results->pluck('error')->filter()->values()->all(), 'No process may crash or deadlock.');
        $this->assertSame(5, $results->where('outcome', 'joined')->count());
        $this->assertSame(9, $results->where('denied', 'full')->count());

        $room->refresh();
        $this->assertSame(5, $room->participants_count);
        $this->assertSame(RoomStatus::Full, $room->status);
        $this->assertSame(5, RoomParticipant::where('room_id', $room->id)->where('status', ParticipantStatus::Joined)->count());
    }

    public function test_simultaneous_duplicate_joins_by_one_user_create_one_seat(): void
    {
        $room = $this->openRoom(['min_participants' => 1, 'max_participants' => 10]);
        $user = User::factory()->create();

        $results = collect($this->raceToJoin($room, array_fill(0, 6, $user->id)));

        $this->assertSame([], $results->pluck('error')->filter()->values()->all());
        $this->assertSame(1, $results->where('outcome', 'joined')->count());
        $this->assertSame(5, $results->where('outcome', 'already')->count());
        $this->assertSame(1, RoomParticipant::where('room_id', $room->id)->count());
        $this->assertSame(1, $room->fresh()->participants_count);
    }

    /**
     * @return Collection<int, User>
     */
    protected function fundedPlayers(int $count, int $balance): Collection
    {
        return User::factory()->count($count)->create()->each(fn (User $user) => app(WalletService::class)
            ->credit(app(WalletService::class)->walletFor($user), $balance, LedgerTransactionType::Deposit, 'seed:'.$user->id));
    }

    public function test_simultaneous_paid_joins_for_the_final_slot_charge_only_the_winner(): void
    {
        config(['competition.paid_entries.enabled' => true]);
        $room = $this->openRoom(['entry_fee_minor' => 5_000, 'min_participants' => 1, 'max_participants' => 3]);
        $early = $this->fundedPlayers(2, 10_000);
        foreach ($early as $user) {
            app(RoomEntryService::class)->join($user, $room);
        }
        $racers = $this->fundedPlayers(6, 10_000);

        $results = collect($this->raceToJoin($room, $racers->pluck('id')->all()));

        $this->assertSame([], $results->pluck('error')->filter()->values()->all(), 'No process may crash or deadlock.');
        $this->assertSame(1, $results->where('outcome', 'joined')->count(), 'Exactly one player gets the last seat.');
        $this->assertSame(5, $results->where('denied', 'full')->count());

        $room->refresh();
        $this->assertSame(3, $room->participants_count);
        $this->assertSame(RoomStatus::Full, $room->status);
        $this->assertSame(3, LedgerTransaction::query()->where('type', LedgerTransactionType::EntryFee)->count());

        // Only the winner paid; the others still have their full balance.
        $balances = $racers->map(fn (User $user) => app(WalletService::class)->getAvailableBalance($user))->sort()->values()->all();
        $this->assertSame([5_000, 10_000, 10_000, 10_000, 10_000, 10_000], $balances);
        $this->assertSame([], app(WalletReconciler::class)->check());
    }

    public function test_simultaneous_paid_joins_by_one_user_charge_once(): void
    {
        config(['competition.paid_entries.enabled' => true]);
        $room = $this->openRoom(['entry_fee_minor' => 5_000, 'min_participants' => 1, 'max_participants' => 10]);
        $user = $this->fundedPlayers(1, 20_000)->first();

        $results = collect($this->raceToJoin($room, array_fill(0, 6, $user->id)));

        $this->assertSame([], $results->pluck('error')->filter()->values()->all());
        $this->assertSame(1, $results->where('outcome', 'joined')->count());
        $this->assertSame(5, $results->where('outcome', 'already')->count());
        $this->assertSame(15_000, app(WalletService::class)->getAvailableBalance($user));
        $this->assertSame(1, LedgerTransaction::query()->where('type', LedgerTransactionType::EntryFee)->count());
    }
}
