<?php

namespace App\Logging;

use App\Support\SensitiveData;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Log channel tap: replaces credential-like context keys (password, token, secret, card,
 * account number, IBAN, CNIC, …; see SensitiveData::CREDENTIALS) with "[removed]" before a
 * record is written. Other values, including exceptions, are left untouched.
 */
class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(fn (LogRecord $record) => $record->with(
            context: self::redact($record->context),
            extra: self::redact($record->extra),
        ));
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(SensitiveData::CREDENTIALS, $key) === 1) {
                $data[$key] = '[removed]';
            } elseif (is_array($value) && $depth < 8) {
                $data[$key] = self::redact($value, $depth + 1);
            }
        }

        return $data;
    }
}
