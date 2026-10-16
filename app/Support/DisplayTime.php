<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Renders stored UTC times in the configured display timezone.
 */
class DisplayTime
{
    public static function local(CarbonInterface $time): CarbonInterface
    {
        return $time->copy()->setTimezone(config('competition.display_timezone'));
    }

    public static function format(CarbonInterface $time, string $format = 'D, j M Y · g:i A'): string
    {
        return static::local($time)->format($format);
    }

    public static function zoneLabel(): string
    {
        return now(config('competition.display_timezone'))->format('T');
    }
}
