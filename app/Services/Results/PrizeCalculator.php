<?php

namespace App\Services\Results;

use LogicException;

/**
 * Turns frozen prize tiers and final ranks into prize entitlements. Pure,
 * integer arithmetic only (minor units).
 *
 * - Tiers are fixed amounts per finishing position: [rank_from, rank_to, amount_each].
 * - Ranked players occupy positions 1..n in rank order; a group sharing rank r
 *   of size k occupies positions r..r+k-1.
 * - A shared rank POOLS the prizes of every position it occupies and splits
 *   the pool equally. Rounding: each player receives floor(pool / k); the
 *   remainder (< k minor units) is NOT given to anyone and is reported as
 *   unallocated, so no player is preferred arbitrarily.
 * - A group whose players are not prize-eligible (e.g. zero correct
 *   answers) receives nothing; its pool is unallocated.
 * - Prize positions with no player (fewer players than places) are unallocated.
 * - Invariant (checked): awarded + unallocated = total configured prize money.
 * Percentage-based allocations are not supported by this version.
 */
class PrizeCalculator
{
    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $tiers  [rank_from, rank_to, amount_minor_each]
     * @param  list<array{participant_id: int, rank: int, eligible: bool}>  $ranked  ranked players only, in rank order
     * @return array{awards: array<int, int>, unallocated_minor: int, total_minor: int, lines: list<array<string, mixed>>}
     */
    public function allocate(array $tiers, array $ranked): array
    {
        $positionAmount = [];
        $total = 0;
        foreach ($tiers as [$from, $to, $amount]) {
            for ($position = $from; $position <= $to; $position++) {
                $positionAmount[$position] = ($positionAmount[$position] ?? 0) + $amount;
                $total += $amount;
            }
        }

        $groups = [];
        foreach ($ranked as $player) {
            $groups[$player['rank']][] = $player;
        }
        ksort($groups);

        $awards = [];
        $lines = [];
        $unallocated = 0;
        $coveredPositions = [];

        foreach ($groups as $rank => $players) {
            $size = count($players);
            $pool = 0;
            for ($position = $rank; $position < $rank + $size; $position++) {
                $pool += $positionAmount[$position] ?? 0;
                $coveredPositions[$position] = true;
            }

            $eligible = $players[0]['eligible'];
            $each = $eligible && $pool > 0 ? intdiv($pool, $size) : 0;
            $remainder = $pool - ($each * $size);
            $unallocated += $remainder;

            foreach ($players as $player) {
                $awards[$player['participant_id']] = $each;
            }

            if ($pool > 0) {
                $lines[] = [
                    'rank' => $rank,
                    'players' => $size,
                    'positions' => [$rank, $rank + $size - 1],
                    'pool_minor' => $pool,
                    'each_minor' => $each,
                    'unallocated_minor' => $remainder,
                    'eligible' => $eligible,
                ];
            }
        }

        foreach ($positionAmount as $position => $amount) {
            if (! isset($coveredPositions[$position])) {
                $unallocated += $amount;
                $lines[] = ['position' => $position, 'unfilled' => true, 'unallocated_minor' => $amount];
            }
        }

        if (array_sum($awards) + $unallocated !== $total) {
            throw new LogicException('Prize allocation does not balance.');
        }

        return [
            'awards' => array_filter($awards, fn ($amount) => $amount > 0),
            'unallocated_minor' => $unallocated,
            'total_minor' => $total,
            'lines' => $lines,
        ];
    }
}
