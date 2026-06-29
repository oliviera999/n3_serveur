<?php

declare(strict_types=1);

namespace App\Util;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Conversion entre l'heure de stockage (serveur, APP_TIMEZONE, par défaut Europe/Paris)
 * et l'heure d'affichage du projet physique (Africa/Casablanca).
 *
 * Contexte : les horodatages applicatifs (reading_time, last_request, …) sont écrits en
 * heure serveur (NOW() / APP_TIMEZONE). Le projet est physiquement à Casablanca ; l'UI,
 * les graphiques Highcharts et stats-updater affichent en Africa/Casablanca. Cette classe
 * factorise la conversion afin que tous les points d'affichage restent cohérents
 * (cf. docs/TIMEZONE_MANAGEMENT.md).
 */
final class DisplayTime
{
    /** Fuseau d'affichage = heure locale réelle du projet (Maroc). */
    public const DISPLAY_TZ = 'Africa/Casablanca';

    /** Fuseau de stockage serveur (APP_TIMEZONE, défaut Europe/Paris). */
    private static function serverTimezone(): DateTimeZone
    {
        return new DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'Europe/Paris');
    }

    private static function displayTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::DISPLAY_TZ);
    }

    /**
     * Convertit un horodatage stocké en heure serveur vers l'heure d'affichage (Casablanca)
     * et le formate. Utilisé comme filtre Twig `localdt`.
     *
     * @param string|DateTimeInterface|null $serverDateTime Horodatage en heure serveur (ex. 'Y-m-d H:i:s')
     *                                                      ou objet DateTime (AbstractDataController)
     * @param string                        $format         Format de sortie (syntaxe date() PHP)
     * @return string Chaîne formatée en heure de Casablanca, ou '' si entrée vide/illisible
     */
    public static function toDisplay(string|DateTimeInterface|null $serverDateTime, string $format = 'd/m/Y H:i:s'): string
    {
        if ($serverDateTime === null) {
            return '';
        }

        if ($serverDateTime instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($serverDateTime)
                ->setTimezone(self::displayTimezone())
                ->format($format);
        }

        if (trim($serverDateTime) === '') {
            return '';
        }

        try {
            $dt = new DateTimeImmutable($serverDateTime, self::serverTimezone());
        } catch (\Exception) {
            return $serverDateTime;
        }

        return $dt->setTimezone(self::displayTimezone())->format($format);
    }

    /**
     * Convertit un horodatage saisi/affiché en heure de Casablanca vers l'heure serveur
     * (APP_TIMEZONE), pour requêtage SQL contre des données stockées en heure serveur.
     *
     * @param string $displayDateTime Horodatage en heure de Casablanca ('Y-m-d H:i:s')
     * @param string $format          Format de sortie (défaut 'Y-m-d H:i:s')
     * @return string Horodatage en heure serveur, ou la valeur d'origine si illisible
     */
    public static function toServer(string $displayDateTime, string $format = 'Y-m-d H:i:s'): string
    {
        if (trim($displayDateTime) === '') {
            return $displayDateTime;
        }

        try {
            $dt = new DateTimeImmutable($displayDateTime, self::displayTimezone());
        } catch (\Exception) {
            return $displayDateTime;
        }

        return $dt->setTimezone(self::serverTimezone())->format($format);
    }
}
