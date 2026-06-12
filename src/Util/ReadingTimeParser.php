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
     * Instance DateTimeZone mise en cache (lazy init).
     * Évite de reconstruire l'objet à chaque appel sur le chemin chaud
     * (détection d'extrema : des dizaines de milliers de points par requête).
     */
    private static ?DateTimeZone $timezone = null;

    /**
     * @return int|null Epoch Unix en secondes, ou null si parsing impossible
     */
    public static function toUnixSeconds(string $readingTime): ?int
    {
        $dt = DateTimeImmutable::createFromFormat(
            self::SQL_FORMAT,
            $readingTime,
            self::dbTimezone()
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

    /**
     * Retourne l'instance partagée DateTimeZone Europe/Paris (créée à la demande).
     */
    private static function dbTimezone(): DateTimeZone
    {
        return self::$timezone ??= new DateTimeZone(self::DB_TIMEZONE);
    }
}
