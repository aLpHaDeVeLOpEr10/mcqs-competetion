<?php

namespace Tests\Unit\Prizes;

use App\Services\Prizes\PrizePoolCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PrizePoolCalculatorTest extends TestCase
{
    protected function resolve(array $rules, int $gross = 0, int $refunds = 0): array
    {
        return (new PrizePoolCalculator)->resolve($rules, $gross, $refunds);
    }

    public function test_fixed_prizes_are_the_configured_amounts(): void
    {
        $pool = $this->resolve(['mode' => 'fixed', 'tiers' => [[1, 1, 10_000], [2, 3, 2_500]]], 50_000, 0);

        $this->assertSame([[1, 1, 10_000], [2, 3, 2_500]], $pool['tiers']);
        $this->assertSame(15_000, $pool['prize_pool_minor']);
        $this->assertSame(0, $pool['platform_amount_minor'], 'Fixed prizes take no platform share.');
        $this->assertSame(50_000, $pool['gross_entry_fees_minor']);
    }

    public function test_percentages_of_a_guaranteed_pool_round_down_per_position(): void
    {
        // Rs 1,000.01 split 50 / 30 / 20: 500.005 → 500.00, 300.003 → 300.00, 200.002 → 200.00.
        $pool = $this->resolve(['mode' => 'percentage', 'pool_basis' => 'guaranteed', 'pool_minor' => 100_001,
            'tiers' => [[1, 1, 0, 5000], [2, 2, 0, 3000], [3, 3, 0, 2000]]]);

        $this->assertSame([[1, 1, 50_000], [2, 2, 30_000], [3, 3, 20_000]], $pool['tiers']);
        $this->assertSame(100_001, $pool['prize_pool_minor']);
        $this->assertSame(1, $pool['percent_rounding_minor']);
    }

    public function test_entry_fee_pool_is_net_of_refunds_and_the_platform_fee(): void
    {
        // Gross Rs 500, refunds Rs 100 → net Rs 400; 12.5 % platform fee = Rs 50; pool Rs 350.
        $pool = $this->resolve(['mode' => 'percentage', 'pool_basis' => 'entry_fees', 'platform_fee_bp' => 1250,
            'tiers' => [[1, 1, 0, 6000], [2, 3, 0, 2000]]], 50_000, 10_000);

        $this->assertSame(40_000, $pool['net_entry_fees_minor']);
        $this->assertSame(5_000, $pool['platform_amount_minor']);
        $this->assertSame(35_000, $pool['prize_pool_minor']);
        $this->assertSame([[1, 1, 21_000], [2, 3, 7_000]], $pool['tiers']);
        $this->assertSame(0, $pool['percent_rounding_minor']);
    }

    public function test_the_platform_fee_rounds_down_in_favour_of_the_prize_pool(): void
    {
        // Net 999 paisa at 10 % = 99.9 → 99; the pool keeps 900.
        $pool = $this->resolve(['mode' => 'percentage', 'pool_basis' => 'entry_fees', 'platform_fee_bp' => 1000, 'tiers' => [[1, 1, 0, 10000]]], 999);

        $this->assertSame(99, $pool['platform_amount_minor']);
        $this->assertSame(900, $pool['prize_pool_minor']);
        $this->assertSame([[1, 1, 900]], $pool['tiers']);
    }

    public function test_tiers_below_100_percent_leave_the_rest_unassigned(): void
    {
        $pool = $this->resolve(['mode' => 'percentage', 'pool_basis' => 'guaranteed', 'pool_minor' => 10_000, 'tiers' => [[1, 1, 0, 4000]]]);

        $this->assertSame([[1, 1, 4_000]], $pool['tiers']);
        $this->assertSame(0, $pool['percent_rounding_minor']);
    }

    public function test_percentages_above_100_or_without_a_basis_are_refused(): void
    {
        try {
            $this->resolve(['mode' => 'percentage', 'pool_basis' => 'guaranteed', 'pool_minor' => 10_000, 'tiers' => [[1, 2, 0, 6000]]]);
            $this->fail('120 % must be refused.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->resolve(['mode' => 'percentage', 'tiers' => [[1, 1, 0, 5000]]]);
    }

    public function test_an_empty_entry_fee_pool_awards_nothing(): void
    {
        $pool = $this->resolve(['mode' => 'percentage', 'pool_basis' => 'entry_fees', 'platform_fee_bp' => 1000, 'tiers' => [[1, 1, 0, 10000]]], 0);

        $this->assertSame(0, $pool['prize_pool_minor']);
        $this->assertSame([[1, 1, 0]], $pool['tiers']);
    }
}
