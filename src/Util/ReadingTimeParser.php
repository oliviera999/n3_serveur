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
    /** Fuseau de stockage par défaut si APP_TIMEZONE n'est pas défini. */
    private const DEFAULT_DB_TIMEZONE = 'Europe/Paris';
    private const SQL_FORMAT = 'Y-m-d H:i:s';

    /**
     * Instance DateTimeZone mise en cache (lazy init), INDEXÉE PAR NOM.
     * Évite de reconstruire l'objet à chaque appel sur le chemin chaud
     * (détection d'extrema : des dizaines de milliers de points par requête),
     * sans figer un fuseau devenu obsolète si APP_TIMEZONE change en cours de process.
     *
     * @var array<string, DateTimeZone>
     */
    private static array $timezones = [];

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
     * Fuseau de stockage des horodatages SQL (créé à la demande, mémoïsé par nom).
     *
     * Lit `APP_TIMEZONE` depuis la 6.34.0 : le fuseau était codé en dur à
     * `Europe/Paris` alors que {@see \App\Util\DisplayTime} et
     * {@see \App\Config\Database::currentUtcOffset()} — qui décident respectivement de
     * l'affichage et du fuseau de session MySQL — lisent tous deux `APP_TIMEZONE`.
     * Changer ce réglage aurait décalé silencieusement les horodatages Highcharts.
     * Un fuseau invalide retombe sur le défaut historique plutôt que de lever.
     */
    private static function dbTimezone(): DateTimeZone
    {
        $name = $_ENV['APP_TIMEZONE'] ?? self::DEFAULT_DB_TIMEZONE;
        if (!is_string($name) || $name === '') {
            $name = self::DEFAULT_DB_TIMEZONE;
        }

        if (!isset(self::$timezones[$name])) {
            try {
                self::$timezones[$name] = new DateTimeZone($name);
            } catch (\Exception) {
                self::$timezones[$name] = new DateTimeZone(self::DEFAULT_DB_TIMEZONE);
            }
        }

        return self::$timezones[$name];
    }
}
