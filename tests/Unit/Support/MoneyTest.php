<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'zero' => [0, 'Rs 0'],
            'whole rupees' => [150_000, 'Rs 1,500'],
            'with paisa' => [123_405, 'Rs 1,234.05'],
            'single paisa' => [1, 'Rs 0.01'],
            'negative' => [-25_050, '-Rs 250.50'],
            'large' => [1_000_000_000_00, 'Rs 1,000,000,000'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_formats_minor_units_exactly(int $minor, string $expected): void
    {
        $this->assertSame($expected, Money::format($minor));
    }

    public static function inputs(): array
    {
        return [
            'whole' => ['1500', 150_000],
            'grouped' => ['1,250.5', 125_050],
            'two decimals' => ['0.05', 5],
            'spaces' => [' 12 000 ', 1_200_000],
            'max digits' => ['999999999.99', 99_999_999_999],
        ];
    }

    #[DataProvider('inputs')]
    public function test_parses_major_units_with_string_arithmetic(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::parse($input));
    }

    public function test_rejects_malformed_or_ambiguous_amounts(): void
    {
        foreach (['', 'abc', '-5', '1.234', '1e3', '0x10', '12.', '.5', '1,25,0.5.0', '1234567890', 'Rs 5', '٣'] as $input) {
            $this->assertNull(Money::parse($input), "'{$input}' must be refused");
        }
    }

    public function test_parsing_and_formatting_round_trip_without_float_error(): void
    {
        foreach ([0, 1, 9, 10, 99, 100, 101, 12_345, 1_000_001, 99_999_999_999] as $minor) {
            $this->assertSame($minor, Money::parse(Money::toInput($minor)), "{$minor} round-trips");
        }
        // 0.1 + 0.2 style inputs stay exact.
        $this->assertSame(30, Money::parse('0.1') + Money::parse('0.2'));
        $this->assertSame('0.30', Money::toInput(Money::parse('0.1') + Money::parse('0.2')));
    }

    public function test_other_currencies_use_their_code(): void
    {
        $this->assertSame('USD 10', Money::format(1_000, 'USD'));
    }

    public function test_zero_fee_is_free(): void
    {
        $this->assertSame('Free', Money::fee(0));
        $this->assertSame('Rs 50', Money::fee(5_000));
    }
}
