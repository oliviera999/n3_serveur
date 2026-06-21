<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\TideStatistics;
use App\Util\ReadingTimeParser;

/**
 * Service de détection des cycles de marée (pompage) dans les niveaux d'eau.
 *
 * Analyse les variations de niveau pour identifier les cycles complets
 * (montée + descente) et calcule les amplitudes et durées.
 *
 * La détection repose sur une hystérésis cumulée (algorithme « zigzag ») :
 * un renversement est confirmé dès que le niveau s'écarte du dernier extrême
 * courant d'au moins le seuil, quelle que soit la vitesse de variation.
 * Les marées lentes (deltas faibles entre lectures consécutives mais grande
 * amplitude totale) sont donc détectées au même titre que les marées rapides.
 *
 * Sémantique distance (EauAquarium) : distance capteur → surface.
 * Distance qui augmente = eau qui descend ; distance qui diminue = eau qui monte.
 */
class TideCycleDetector
{
    /** Hystérésis (cm) : écart cumulé depuis le dernier extrême pour confirmer un renversement */
    public const VARIATION_THRESHOLD_CM = 2.0;
    private const EXTREMA_MIN_INTERVAL_SECONDS = 10;
    /** Nombre de demi-cycles récents utilisés pour la tendance */
    private const TREND_RECENT_SWINGS = 3;

    /**
     * Détecte les cycles de marée dans une série de niveaux d'eau.
     *
     * Un cycle correspond au passage d'un extrême confirmé au suivant
     * (montée puis descente, ou l'inverse).
     *
     * @param array<float|int|null> $levels Niveaux d'eau en ordre chronologique ASC
     * @param array<string> $times Timestamps correspondants
     * @param float $threshold Hystérésis minimum (défaut: 2.0 cm)
     * @return array{
     *     amplitudes: array<float>,
     *     cycleDurations: array<float>,
     *     cycles: int,
     *     cycleMin: float|null,
     *     cycleMax: float|null
     * }
     */
    public function detectCycles(
        array $levels,
        array $times,
        float $threshold = self::VARIATION_THRESHOLD_CM,
        ?array $zigzag = null
    ): array {
        $cycleMin = null;
        $cycleMax = null;
        foreach ($levels as $level) {
            if ($level === null || !is_numeric($level)) {
                continue;
            }
            $level = (float) $level;
            $cycleMin = $cycleMin === null ? $level : min($cycleMin, $level);
            $cycleMax = $cycleMax === null ? $level : max($cycleMax, $level);
        }

        // Réutilise une passe zigzag pré-calculée si fournie (cf. analyzeSeries),
        // sinon la calcule à la volée (rétro-compatibilité).
        $zigzag ??= $this->findAlternatingExtrema($levels, $threshold);
        $extrema = $zigzag['extrema'];

        $amplitudes = [];
        $cycleDurations = [];
        $extremaCount = count($extrema);
        for ($i = 1; $i < $extremaCount; $i++) {
            $amplitudes[] = abs($extrema[$i]['value'] - $extrema[$i - 1]['value']);

            $startTs = ReadingTimeParser::toUnixSeconds((string) ($times[$extrema[$i - 1]['idx']] ?? ''));
            $endTs = ReadingTimeParser::toUnixSeconds((string) ($times[$extrema[$i]['idx']] ?? ''));
            if ($startTs !== null && $endTs !== null) {
                $cycleDuration = ($endTs - $startTs) / 3600;
                if ($cycleDuration > 0) {
                    $cycleDurations[] = $cycleDuration;
                }
            }
        }

        return [
            'amplitudes' => $amplitudes,
            'cycleDurations' => $cycleDurations,
            'cycles' => count($amplitudes),
            'cycleMin' => $cycleMin,
            'cycleMax' => $cycleMax,
        ];
    }

    /**
     * Tendance physique récente du niveau d'eau (pas la distance brute).
     *
     * Basée sur les derniers demi-cycles détectés par hystérésis cumulée :
     * si leurs variations se compensent, le niveau est considéré stable.
     *
     * @param array<float|int|null> $levels Niveaux distance en ordre chronologique ASC
     * @return 'rising'|'falling'|'stable'|null rising = eau monte (distance diminue)
     */
    public function detectCurrentTrend(
        array $levels,
        float $threshold = self::VARIATION_THRESHOLD_CM,
        ?array $zigzag = null
    ): ?string {
        // Réutilise une passe zigzag pré-calculée si fournie (cf. analyzeSeries).
        $zigzag ??= $this->findAlternatingExtrema($levels, $threshold);
        $points = $zigzag['extrema'];
        if ($zigzag['provisional'] !== null) {
            $points[] = $zigzag['provisional'];
        }

        if (count($points) < 2) {
            return null;
        }

        $swings = [];
        $pointCount = count($points);
        for ($i = 1; $i < $pointCount; $i++) {
            $swings[] = $points[$i]['value'] - $points[$i - 1]['value'];
        }

        $recent = array_slice($swings, -self::TREND_RECENT_SWINGS);
        $sum = array_sum($recent);

        if (abs($sum) <= $threshold) {
            return 'stable';
        }

        // Distance diminue → eau monte ; distance augmente → eau descend
        return $sum < 0 ? 'rising' : 'falling';
    }

    /**
     * Libellé FR pour une tendance détectée.
     */
    public static function trendLabel(?string $trend): ?string
    {
        return match ($trend) {
            'rising' => 'Eau en montée',
            'falling' => 'Eau en descente',
            'stable' => 'Stable',
            default => null,
        };
    }

    /**
     * @param array<float> $cycleDurations Durées des cycles en heures
     * @return array{frequency: float|null, frequencyStddev: float|null}
     */
    public function computeFrequencyStats(array $cycleDurations, int $cycles, float $totalHours): array
    {
        // Calcul pur délégué à App\Domain\TideStatistics (même contrat).
        return TideStatistics::frequencyStats($cycleDurations, $cycles, $totalHours);
    }

    /**
     * @param array<float> $amplitudes Amplitudes des cycles
     * @return array{marnage: float|null, marnageStddev: float|null}
     */
    public function computeMarnageStats(array $amplitudes): array
    {
        // Calcul pur délégué à App\Domain\TideStatistics (même contrat).
        return TideStatistics::marnageStats($amplitudes);
    }

    /**
     * Variations cumulées d'un niveau, mesurées entre extrema successifs
     * (hystérésis cumulée) : les dérives lentes sont comptabilisées même si
     * chaque delta unitaire reste sous le seuil.
     *
     * @param array<float|int|null> $levels Niveaux d'eau
     * @return array{positive: float, negative: float, global: float}
     */
    public function computeVariations(array $levels, float $threshold = 1.0, ?array $zigzag = null): array
    {
        $count = count($levels);
        if ($count < 2) {
            return ['positive' => 0.0, 'negative' => 0.0, 'global' => 0.0];
        }

        // Réutilise une passe zigzag pré-calculée si fournie (doit avoir été
        // calculée sur la même série et le même seuil).
        $zigzag ??= $this->findAlternatingExtrema($levels, $threshold);
        $points = $zigzag['extrema'];
        if ($zigzag['provisional'] !== null) {
            $points[] = $zigzag['provisional'];
        }

        // Agrégation pure des swings déléguée à App\Domain\TideStatistics.
        $swings = TideStatistics::aggregateSwings($points);

        $first = $levels[0];
        $last = $levels[$count - 1];
        $global = 0.0;
        if ($first !== null && $last !== null && is_numeric($first) && is_numeric($last)) {
            $global = (float) $last - (float) $first;
        }

        return [
            'positive' => $swings['positive'],
            'negative' => $swings['negative'],
            'global' => $global,
        ];
    }

    /**
     * Détecte les extrema (pics/creux distance) horodatés sur une série EauAquarium.
     *
     * @param array<float|int|null> $levels Niveaux en ordre chronologique ASC
     * @param array<string> $times Horodatages correspondants
     * @return array{
     *   peaks: array<array{0:int,1:float}>,
     *   troughs: array<array{0:int,1:float}>
     * }
     */
    public function detectExtremaSeries(
        array $levels,
        array $times,
        float $threshold = self::VARIATION_THRESHOLD_CM,
        int $minIntervalSeconds = self::EXTREMA_MIN_INTERVAL_SECONDS
    ): array {
        $values = [];
        $timestamps = [];
        $count = min(count($levels), count($times));
        for ($i = 0; $i < $count; $i++) {
            $level = $levels[$i];
            if ($level === null || !is_numeric($level)) {
                continue;
            }
            $tsMs = ReadingTimeParser::toUnixMs((string) $times[$i]);
            if ($tsMs === null) {
                continue;
            }
            $values[] = (float) $level;
            $timestamps[] = $tsMs;
        }

        if (count($values) < 3) {
            return ['peaks' => [], 'troughs' => []];
        }

        $zigzag = $this->findAlternatingExtrema($values, $threshold);
        $points = $zigzag['extrema'];
        if ($zigzag['provisional'] !== null) {
            $points[] = $zigzag['provisional'];
        }

        $minIntervalMs = max(0, $minIntervalSeconds) * 1000;
        $peaks = [];
        $troughs = [];
        $lastEventTs = null;

        foreach ($points as $point) {
            $eventTs = $timestamps[$point['idx']];
            if ($lastEventTs !== null && ($eventTs - $lastEventTs) < $minIntervalMs) {
                continue;
            }
            if ($point['isPeak']) {
                $peaks[] = [$eventTs, $point['value']];
            } else {
                $troughs[] = [$eventTs, $point['value']];
            }
            $lastEventTs = $eventTs;
        }

        return ['peaks' => $peaks, 'troughs' => $troughs];
    }

    /**
     * Exécute UNE seule passe zigzag et retourne son résultat brut, réutilisable.
     *
     * Permet de partager le coût de la détection d'extrema entre plusieurs
     * consommateurs (detectCycles, detectCurrentTrend, computeVariations) au lieu
     * de relancer findAlternatingExtrema pour chacun.
     *
     * @param array<float|int|null> $levels Série en ordre chronologique ASC
     * @return array{
     *   extrema: array<array{idx:int, value:float, isPeak:bool}>,
     *   provisional: array{idx:int, value:float, isPeak:bool}|null,
     *   trend: int
     * }
     */
    public function analyzeSeries(array $levels, float $threshold = self::VARIATION_THRESHOLD_CM): array
    {
        return $this->findAlternatingExtrema($levels, $threshold);
    }

    /**
     * Extrait les extrema alternés d'une série par hystérésis cumulée (zigzag).
     *
     * Un extrême est confirmé dès que la série s'en écarte d'au moins le seuil,
     * indépendamment du nombre de lectures nécessaires pour y parvenir.
     *
     * @param array<float|int|null> $levels Série en ordre chronologique ASC
     * @return array{
     *   extrema: array<array{idx:int, value:float, isPeak:bool}>,
     *   provisional: array{idx:int, value:float, isPeak:bool}|null,
     *   trend: int
     * } extrema = confirmés ; provisional = extrême courant non encore confirmé ;
     *   trend = 1 distance en hausse, -1 en baisse, 0 seuil jamais franchi.
     *   idx référence l'indice dans la série d'entrée.
     */
    private function findAlternatingExtrema(array $levels, float $threshold): array
    {
        $indexes = [];
        $values = [];
        foreach ($levels as $i => $level) {
            if ($level === null || !is_numeric($level)) {
                continue;
            }
            $indexes[] = $i;
            $values[] = (float) $level;
        }

        $n = count($values);
        if ($n < 2) {
            return ['extrema' => [], 'provisional' => null, 'trend' => 0];
        }

        $extrema = [];
        $trend = 0;
        $minPos = 0;
        $maxPos = 0;
        $extremePos = 0;

        for ($k = 1; $k < $n; $k++) {
            $v = $values[$k];

            if ($trend === 0) {
                if ($v <= $values[$minPos]) {
                    $minPos = $k;
                }
                if ($v >= $values[$maxPos]) {
                    $maxPos = $k;
                }
                if ($v - $values[$minPos] >= $threshold) {
                    $extrema[] = ['idx' => $indexes[$minPos], 'value' => $values[$minPos], 'isPeak' => false];
                    $trend = 1;
                    $extremePos = $k;
                } elseif ($values[$maxPos] - $v >= $threshold) {
                    $extrema[] = ['idx' => $indexes[$maxPos], 'value' => $values[$maxPos], 'isPeak' => true];
                    $trend = -1;
                    $extremePos = $k;
                }
                continue;
            }

            if ($trend === 1) {
                if ($v >= $values[$extremePos]) {
                    $extremePos = $k;
                } elseif ($values[$extremePos] - $v >= $threshold) {
                    $extrema[] = ['idx' => $indexes[$extremePos], 'value' => $values[$extremePos], 'isPeak' => true];
                    $trend = -1;
                    $extremePos = $k;
                }
                continue;
            }

            if ($v <= $values[$extremePos]) {
                $extremePos = $k;
            } elseif ($v - $values[$extremePos] >= $threshold) {
                $extrema[] = ['idx' => $indexes[$extremePos], 'value' => $values[$extremePos], 'isPeak' => false];
                $trend = 1;
                $extremePos = $k;
            }
        }

        $provisional = null;
        if ($trend !== 0) {
            $provisional = [
                'idx' => $indexes[$extremePos],
                'value' => $values[$extremePos],
                'isPeak' => $trend === 1,
            ];
        }

        return ['extrema' => $extrema, 'provisional' => $provisional, 'trend' => $trend];
    }
}
