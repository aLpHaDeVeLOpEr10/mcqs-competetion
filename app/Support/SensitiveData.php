<?php

namespace App\Support;

/**
 * Removes credentials and payment secrets from data before it is logged or stored.
 */
class SensitiveData
{
    /** Credentials and payment secrets: never stored anywhere (audit metadata included). */
    public const CREDENTIALS = '/(^|_)(password|passwd|secret|token|pin|cvv|cvc|card|card_?number|pan|otp|api_?key|authori[sz]ation|credentials?|account_?number|iban|cnic)(_|$)/i';

    /** Also signatures, hashes and phone numbers: for raw provider payloads. */
    public const KEYS = '/(^|_)(password|passwd|secret|token|pin|cvv|cvc|card|card_?number|pan|otp|api_?key|authori[sz]ation|credentials?|signature|hash|secure_?hash|mobile|msisdn|cnic)(_|$)/i';

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function strip(array $data, int $maxLength = 191, string $pattern = self::KEYS): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match($pattern, $key)) {
                $clean[$key] = '[removed]';

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => static::strip($value, $maxLength, $pattern),
                is_string($value) => mb_substr($value, 0, $maxLength),
                is_scalar($value) || $value === null => $value,
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof \Stringable => mb_substr((string) $value, 0, $maxLength),
                default => '[unsupported]',
            };
        }

        return $clean;
    }
}
