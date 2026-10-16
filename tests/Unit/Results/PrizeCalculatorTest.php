<?php

namespace Tests\Unit\Results;

use App\Services\Results\PrizeCalculator;
use PHPUnit\Framework\TestCase;

class PrizeCalculatorTest extends TestCase
{
    protected function p(int $id, int $rank, bool $eligible = true): array
    {
        return ['participant_id' => $id, 'rank' => $rank, 'eligible' => $eligible];
    }

    protected function allocate(array $tiers, array $ranked): array
    {
        return (new PrizeCalculator)->allocate($tiers, $ranked);
    }

    public function test_distinct_ranks_receive_their_tier(): void
    {
        $r = $this->allocate([[1, 1, 100_000], [2, 3, 25_000]], [$this->p(1, 1), $this->p(2, 2), $this->p(3, 3), $this->p(4, 4)]);

        $this->assertSame([1 => 100_000, 2 => 25_000, 3 => 25_000], $r['awards']);
        $this->assertSame(0, $r['unallocated_minor']);
        $this->assertSame(150_000, $r['total_minor']);
    }

    public function test_a_shared_first_place_pools_and_splits_first_and_second_prizes(): void
    {
        $r = $this->allocate([[1, 1, 100_000], [2, 2, 50_000]], [$this->p(1, 1), $this->p(2, 1), $this->p(3, 3)]);

        $this->assertSame([1 => 75_000, 2 => 75_000], $r['awards']);
        $this->assertSame(0, $r['unallocated_minor']);
    }

    public function test_rounding_floors_each_share_and_reports_the_remainder_as_unallocated(): void
    {
        // Rs 1,000.00 shared by three: 333.33 each, 0.01 unallocated (never given to one player).
        $r = $this->allocate([[1, 3, 33_334]], [$this->p(1, 1), $this->p(2, 1), $this->p(3, 1)]);

        $this->assertSame([1 => 33_334, 2 => 33_334, 3 => 33_334], $r['awards']);
        $this->assertSame(0, $r['unallocated_minor']);

        $odd = $this->allocate([[1, 1, 100_000]], [$this->p(1, 1), $this->p(2, 1), $this->p(3, 1)]);
        $this->assertSame([1 => 33_333, 2 => 33_333, 3 => 33_333], $odd['awards']);
        $this->assertSame(1, $odd['unallocated_minor']);
        $this->assertSame($odd['total_minor'], array_sum($odd['awards']) + $odd['unallocated_minor']);
    }

    public function test_a_tie_straddling_the_last_prize_place_shares_it(): void
    {
        // Prizes for places 1–2; three players tie for 2nd: they share place 2's prize (and places 3–4 pay nothing).
        $r = $this->allocate([[1, 1, 60_000], [2, 2, 30_000]], [$this->p(1, 1), $this->p(2, 2), $this->p(3, 2), $this->p(4, 2)]);

        $this->assertSame([1 => 60_000, 2 => 10_000, 3 => 10_000, 4 => 10_000], $r['awards']);
        $this->assertSame(0, $r['unallocated_minor']);
    }

    public function test_fewer_players_than_prize_places_leaves_the_rest_unallocated(): void
    {
        $r = $this->allocate([[1, 1, 50_000], [2, 5, 10_000]], [$this->p(1, 1), $this->p(2, 2)]);

        $this->assertSame([1 => 50_000, 2 => 10_000], $r['awards']);
        $this->assertSame(30_000, $r['unallocated_minor']);
    }

    public function test_ineligible_players_receive_nothing_and_their_places_are_unallocated(): void
    {
        $r = $this->allocate([[1, 3, 10_000]], [$this->p(1, 1), $this->p(2, 2, false), $this->p(3, 2, false)]);

        $this->assertSame([1 => 10_000], $r['awards']);
        $this->assertSame(20_000, $r['unallocated_minor']);
    }

    public function test_no_tiers_and_no_players(): void
    {
        $this->assertSame([], $this->allocate([], [$this->p(1, 1)])['awards']);

        $empty = $this->allocate([[1, 1, 5_000]], []);
        $this->assertSame([], $empty['awards']);
        $this->assertSame(5_000, $empty['unallocated_minor']);
    }

    public function test_awards_and_unallocated_always_balance_the_configured_total(): void
    {
        $tiers = [[1, 1, 99_999], [2, 4, 33_333], [5, 9, 7]];

        foreach ([[1, 1, 1, 4, 4, 6, 7, 7, 7, 10], [1, 2, 3], [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1]] as $ranks) {
            $ranked = array_map(fn ($rank, $i) => $this->p($i + 1, $rank), $ranks, array_keys($ranks));
            $r = $this->allocate($tiers, $ranked);

            $this->assertSame($r['total_minor'], array_sum($r['awards']) + $r['unallocated_minor']);
        }
    }
}
