<?php

declare(strict_types=1);

namespace App\Util;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Parse les horodatages SQL FFP3 (stockés en Europe/Paris) vers epoch Unix.
 */
final class ReadingTimeParser
{
    private const DB_TIMEZONE = 'Europe/Paris';
    private const SQL_FORMAT = 'Y-m-d H:i:s';

    /**
     * @return int|null Epoch Unix en secondes, ou null si parsing impossible
     */
    public static function toUnixSeconds(string $readingTime): ?int
    {
        $dt = DateTimeImmutable::createFromFormat(
            self::SQL_FORMAT,
            $readingTime,
            new DateTimeZone(self::DB_TIMEZONE)
        );
        if ($dt !== false) {
            return $dt->getTimestamp();
        }

        $fallback = strtotime($readingTime);
        return $fallback !== false ? $fallback : null;
    }

    /**
     * @return int|null Epoch Unix en millisecondes (Highcharts), ou null si parsing impossible
     */
    public static function toUnixMs(string $readingTime): ?int
    {
        $seconds = self::toUnixSeconds($readingTime);
        return $seconds !== null ? $seconds * 1000 : null;
    }
}
