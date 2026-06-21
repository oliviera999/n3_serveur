<?php

declare(strict_types=1);

namespace App\Domain;

use App\Util\MathUtils;

/**
 * Calculs statistiques purs sur les cycles de marée (pompage).
 *
 * Cette classe ne contient AUCUNE entrée/sortie : pas de PDO, pas de $_ENV,
 * pas d'horloge. Elle reçoit des tableaux/scalaires déjà extraits et renvoie
 * des valeurs/structures. L'algorithme de détection des extrema (zigzag) reste
 * la responsabilité de {@see \App\Service\TideCycleDetector} ; cette classe se
 * limite à l'agrégation arithmétique de ses résultats.
 *
 * Les contrats (clés de tableau, arrondis) reproduisent exactement ceux des
 * méthodes historiques de TideCycleDetector vers lesquelles celui-ci délègue.
 */
final class TideStatistics
{
    /**
     * Fréquence des cycles et écart-type de la fréquence par cycle.
     *
     * @param array<float> $cycleDurations Durées des cycles en heures
     * @param int          $cycles         Nombre de cycles détectés
     * @param float        $totalHours     Durée totale de la période, en heures
     * @return array{frequency: float|null, frequencyStddev: float|null}
     */
    public static function frequencyStats(array $cycleDurations, int $cycles, float $totalHours): array
    {
        $frequency = ($totalHours > 0 && $cycles > 0) ? $cycles / $totalHours : null;

        $frequencyStddev = null;
        if (count($cycleDurations) > 1) {
            $cycleFrequencies = array_map(static function ($duration) {
                return $duration > 0 ? 1 / $duration : 0;
            }, $cycleDurations);

            $frequencyStddev = MathUtils::standardDeviation($cycleFrequencies);
        }

        return [
            'frequency' => $frequency,
            'frequencyStddev' => $frequencyStddev,
        ];
    }

    /**
     * Marnage moyen (amplitude moyenne) et son écart-type.
     *
     * @param array<float> $amplitudes Amplitudes des cycles
     * @return array{marnage: float|null, marnageStddev: float|null}
     */
    public static function marnageStats(array $amplitudes): array
    {
        $cycles = count($amplitudes);

        return [
            'marnage' => $cycles > 0 ? array_sum($amplitudes) / $cycles : null,
            'marnageStddev' => $cycles > 1 ? MathUtils::standardDeviation($amplitudes) : null,
        ];
    }

    /**
     * Agrège les variations cumulées entre extrema successifs d'un zigzag.
     *
     * Somme séparément les deltas positifs (montées de distance) et la valeur
     * absolue des deltas négatifs (descentes). Les points sont supposés ordonnés
     * chronologiquement (extrema confirmés + provisionnel éventuel).
     *
     * @param array<array{value: float}> $points Extrema ordonnés (au moins `value`)
     * @return array{positive: float, negative: float}
     */
    public static function aggregateSwings(array $points): array
    {
        $positive = 0.0;
        $negative = 0.0;

        $points = array_values($points);
        $pointCount = count($points);
        for ($i = 1; $i < $pointCount; $i++) {
            $delta = $points[$i]['value'] - $points[$i - 1]['value'];
            if ($delta > 0) {
                $positive += $delta;
            } elseif ($delta < 0) {
                $negative += abs($delta);
            }
        }

        return [
            'positive' => $positive,
            'negative' => $negative,
        ];
    }
}
