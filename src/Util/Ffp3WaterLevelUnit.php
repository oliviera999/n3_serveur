<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Les niveaux FFP3 (EauAquarium, EauReserve, EauPotager) sont stockés en millimètres
 * côté firmware / BDD ; l'interface les présente en centimètres (÷10).
 */
final class Ffp3WaterLevelUnit
{
    public const MM_PER_CM = 10.0;

    private const SENSOR_KEYS = ['EauAquarium', 'EauReserve', 'EauPotager'];

    /**
     * @param array<string, mixed>|null $row Une ligne ffp3Data (ou équivalent)
     * @return array<string, mixed>|null
     */
    public static function scaleSensorRowFromMmToCm(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $out = $row;
        foreach (self::SENSOR_KEYS as $k) {
            if (!array_key_exists($k, $out) || $out[$k] === null || $out[$k] === '') {
                continue;
            }
            $out[$k] = (float) $out[$k] / self::MM_PER_CM;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function scaleSensorRowsFromMmToCm(array $rows): array
    {
        // $rows est déjà une copie locale (passage par valeur) : on modifie en place
        // par référence pour éviter de recopier chaque ligne via scaleSensorRowFromMmToCm.
        foreach ($rows as &$row) {
            foreach (self::SENSOR_KEYS as $k) {
                if (!array_key_exists($k, $row) || $row[$k] === null || $row[$k] === '') {
                    continue;
                }
                $row[$k] = (float) $row[$k] / self::MM_PER_CM;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Statistiques aplanies passées aux templates aquaponie (min_eauaqua, avg_eaureserve, …).
     *
     * @param array<string, float|int|string|null> $flattened
     * @return array<string, float|int|string|null>
     */
    public static function scaleAquaponieFlattenedStatsFromMmToCm(array $flattened): array
    {
        $suffixes = ['eauaqua', 'eaureserve', 'eaupota'];
        $prefixes = ['min_', 'max_', 'avg_', 'stddev_'];
        foreach ($suffixes as $s) {
            foreach ($prefixes as $p) {
                $key = $p . $s;
                if (!array_key_exists($key, $flattened)) {
                    continue;
                }
                $v = $flattened[$key];
                if ($v === null || $v === '') {
                    continue;
                }
                $flattened[$key] = (float) $v / self::MM_PER_CM;
            }
        }

        return $flattened;
    }

    /**
     * Bloc stats du dashboard FFP3 (min / max / avg / stddev par capteur).
     *
     * @param array<string, array<string, float|int|null>> $stats
     * @return array<string, array<string, float|int|null>>
     */
    public static function scaleDashboardWaterStatsFromMmToCm(array $stats): array
    {
        foreach (self::SENSOR_KEYS as $k) {
            if (!isset($stats[$k]) || !is_array($stats[$k])) {
                continue;
            }
            foreach (['min', 'max', 'avg', 'stddev'] as $f) {
                if (!array_key_exists($f, $stats[$k]) || $stats[$k][$f] === null) {
                    continue;
                }
                $stats[$k][$f] = (float) $stats[$k][$f] / self::MM_PER_CM;
            }
        }

        return $stats;
    }
}
