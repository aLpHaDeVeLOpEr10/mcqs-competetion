<?php

namespace App\Support;

/**
 * Formatting for integer minor-unit amounts. Uses integer arithmetic only.
 */
class Money
{
    public static function format(int $amountMinor, ?string $currency = null): string
    {
        $currency ??= config('competition.currency.code');
        $perMajor = (int) config('competition.currency.minor_units_per_major');

        $sign = $amountMinor < 0 ? '-' : '';
        $absolute = abs($amountMinor);
        $major = number_format(intdiv($absolute, $perMajor));
        $minor = $absolute % $perMajor;

        $symbol = $currency === 'PKR' ? 'Rs' : $currency;
        $digits = strlen((string) ($perMajor - 1));
        $fraction = $minor === 0 ? '' : '.'.str_pad((string) $minor, $digits, '0', STR_PAD_LEFT);

        return "{$sign}{$symbol} {$major}{$fraction}";
    }

    /**
     * Parse a major-unit amount typed by staff ("1,250.5") into minor units
     * using string arithmetic only. Returns null for anything malformed.
     */
    public static function parse(string $input): ?int
    {
        $value = str_replace([',', ' '], '', trim($input));

        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }

        $perMajor = (int) config('competition.currency.minor_units_per_major');
        $fraction = str_pad($m[2] ?? '', strlen((string) ($perMajor - 1)), '0');

        return (int) $m[1] * $perMajor + (int) $fraction;
    }

    /**
     * Major-unit string for form fields (no currency symbol, no grouping).
     */
    public static function toInput(int $amountMinor): string
    {
        $perMajor = (int) config('competition.currency.minor_units_per_major');
        $minor = $amountMinor % $perMajor;

        return intdiv($amountMinor, $perMajor).($minor === 0 ? '' : '.'.str_pad((string) $minor, strlen((string) ($perMajor - 1)), '0', STR_PAD_LEFT));
    }

    /**
     * Entry fee label: "Free" for zero.
     */
    public static function fee(int $amountMinor, ?string $currency = null): string
    {
        return $amountMinor === 0 ? 'Free' : static::format($amountMinor, $currency);
    }
}
