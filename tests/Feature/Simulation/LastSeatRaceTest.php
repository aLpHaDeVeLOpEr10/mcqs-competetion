<?php

namespace Tests\Feature\Simulation;

use App\Enums\LedgerTransactionType;
use App\Enums\ParticipantStatus;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Competition\RoomEntryService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\AssertsFinancialInvariants;
use Tests\Support\CreatesRooms;
use Tests\TestCase;

/**
 * Scenario F: two users race for the last seat of a paid room from separate PHP
 * processes (separate database connections), released at the same instant.
 * Repeated over several rooms to give the race real chances to interleave.
 */
class LastSeatRaceTest extends TestCase
{
    use AssertsFinancialInvariants, CreatesRooms, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['competition.paid_entries.enabled' => true]);
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    protected function funded(): User
    {
        $user = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->walletFor($user), 10_000, LedgerTransactionType::Deposit, 'seed:'.$user->id);

        return $user;
    }

    /**
     * @param  list<int>  $userIds
     * @return list<array<string, string>>
     */
    protected function race(Room $room, array $userIds): array
    {
        $startAt = sprintf('%.6F', microtime(true) + 4);
        $processes = [];
        foreach ($userIds as $userId) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/concurrent-join.php'), (string) $room->id, (string) $userId, $startAt],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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
            $this->assertIsArray($decoded, "Child output: {$out} {$err}");
            $results[] = $decoded;
        }

        return $results;
    }

    public function test_scenario_f_two_users_race_for_the_final_seat(): void
    {
        foreach (range(1, 3) as $round) {
            $room = $this->openRoom(['entry_fee_minor' => 2_500, 'min_participants' => 2, 'max_participants' => 10, 'name' => "Race {$round}"]);
            foreach (range(1, 9) as $i) {
                app(RoomEntryService::class)->join($this->funded(), $room->fresh());
            }
            [$a, $b] = [$this->funded(), $this->funded()];

            $results = collect($this->race($room, [$a->id, $b->id]));

            $this->assertSame([], $results->pluck('error')->filter()->values()->all(), "Round {$round}: no crash or deadlock.");
            $this->assertSame(1, $results->where('outcome', 'joined')->count(), "Round {$round}: exactly one wins the seat.");
            $this->assertSame(1, $results->where('denied', 'full')->count(), "Round {$round}: the other is told the room is full.");

            $confirmed = RoomParticipant::query()->where('room_id', $room->id)->where('status', ParticipantStatus::Joined)->count();
            $this->assertSame(10, $confirmed, "Round {$round}: capacity is never exceeded.");
            $this->assertSame(10, (int) $room->fresh()->participants_count);

            // Only the winner paid.
            $balances = [$a->id => app(WalletService::class)->getAvailableBalance($a), $b->id => app(WalletService::class)->getAvailableBalance($b)];
            sort($balances);
            $this->assertSame([7_500, 10_000], array_values($balances), "Round {$round}: the loser was not charged.");
        }

        $this->assertFinancialInvariants();
    }
}
