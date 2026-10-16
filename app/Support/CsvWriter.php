<?php

namespace App\Support;

/**
 * Streams CSV rows to a file handle, neutralizing spreadsheet formula
 * injection: any text cell starting with =, +, -, @, tab or carriage return
 * is prefixed with an apostrophe so Excel / Sheets treat it as text. Numbers
 * are written as numbers (negative amounts stay numeric).
 */
class CsvWriter
{
    /**
     * @param  resource  $handle
     */
    public function __construct(protected $handle) {}

    /**
     * @param  array<int, mixed>  $row
     */
    public function write(array $row): void
    {
        fputcsv($this->handle, array_map([self::class, 'safe'], $row), ',', '"', '');
    }

    public static function safe(mixed $value): string|int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if ($value instanceof \BackedEnum) {
            $value = (string) $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
