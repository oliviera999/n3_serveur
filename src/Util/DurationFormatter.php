<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Formateur de durée lisible entre deux dates.
 * Remplace les 4 implémentations disparates dans les contrôleurs.
 */
final class DurationFormatter
{
    /**
     * Format court : "3 j, 2 h, 15 min"
     */
    public static function short(string $start, string $end): string
    {
        $seconds = strtotime($end) - strtotime($start);
        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        return "$days j, $hours h, $minutes min";
    }

    /**
     * Format long : "3 jours, 2 heures, 15 minutes, 30 secondes"
     */
    public static function long(string $start, string $end): string
    {
        $seconds = strtotime($end) - strtotime($start);
        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = (int) ($seconds % 60);

        return "$days jours, $hours heures, $minutes minutes, $secs secondes";
    }
}
