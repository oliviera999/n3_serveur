<?php

declare(strict_types=1);

namespace App\Service;

use App\Util\ReadingTimeParser;

/**
 * Service de détection des cycles de marée (pompage) dans les niveaux d'eau.
 *
 * Analyse les variations de niveau pour identifier les cycles complets
 * (montée + descente) et calcule les amplitudes et durées.
 *
 * Sémantique distance (EauAquarium) : distance capteur → surface.
 * Distance qui augmente = eau qui descend ; distance qui diminue = eau qui monte.
 */
class TideCycleDetector
{
    /** Variation minimale (cm) pour être considérée comme significative */
    public const VARIATION_THRESHOLD_CM = 2.0;
    private const EXTREMA_MIN_INTERVAL_SECONDS = 10;

    /**
     * Détecte les cycles de marée dans une série de niveaux d'eau
     *
     * @param array<float|int|null> $levels Niveaux d'eau en ordre chronologique ASC
     * @param array<string> $times Timestamps correspondants
     * @param float $threshold Seuil de variation minimum (défaut: 2.0 cm)
     * @return array{
     *     amplitudes: array<float>,
     *     cycleDurations: array<float>,
     *     cycles: int,
     *     cycleMin: float|null,
     *     cycleMax: float|null
     * }
     */
    public function detectCycles(array $levels, array $times, float $threshold = self::VARIATION_THRESHOLD_CM): array
    {
        $count = count($levels);
        if ($count < 2) {
            return [
                'amplitudes' => [],
                'cycleDurations' => [],
                'cycles' => 0,
                'cycleMin' => null,
                'cycleMax' => null,
            ];
        }

        $cycleMin = $levels[0];
        $cycleMax = $levels[0];
        $direction = null;
        $hasRisen = false;
        $hasFallen = false;
        $amplitudes = [];
        $cycleDurations = [];
        $cycleStartTime = $times[0];

        for ($i = 1; $i < $count; $i++) {
            $level = $levels[$i];
            if ($level === null || !is_numeric($level)) {
                continue;
            }
            $level = (float) $level;

            $cycleMin = min($cycleMin ?? $level, $level);
            $cycleMax = max($cycleMax ?? $level, $level);

            $prev = $levels[$i - 1];
            if ($prev === null || !is_numeric($prev)) {
                continue;
            }
            $delta = $level - (float) $prev;

            if (abs($delta) <= $threshold) {
                continue;
            }

            $currentDir = $delta > 0 ? 1 : -1;

            if ($direction === null) {
                $direction = $currentDir;
                $hasRisen = ($currentDir === 1);
                $hasFallen = ($currentDir === -1);
            }

            if ($currentDir === 1) {
                $hasRisen = true;
            } else {
                $hasFallen = true;
            }

            if ($direction !== $currentDir && $hasRisen && $hasFallen) {
                $amplitude = ($cycleMax ?? $level) - ($cycleMin ?? $level);
                $amplitudes[] = $amplitude;

                $startTs = ReadingTimeParser::toUnixSeconds((string) $cycleStartTime);
                $endTs = ReadingTimeParser::toUnixSeconds((string) $times[$i - 1]);
                if ($startTs !== null && $endTs !== null) {
                    $cycleDuration = ($endTs - $startTs) / 3600;
                    if ($cycleDuration > 0) {
                        $cycleDurations[] = $cycleDuration;
                    }
                }

                $cycleMin = $level;
                $cycleMax = $level;
                $direction = $currentDir;
                $hasRisen = ($currentDir === 1);
                $hasFallen = ($currentDir === -1);
                $cycleStartTime = $times[$i - 1];
            } elseif ($direction !== $currentDir) {
                $direction = $currentDir;
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
     * @param array<float|int|null> $levels Niveaux distance en ordre chronologique ASC
     * @return 'rising'|'falling'|'stable'|null rising = eau monte (distance diminue)
     */
    public function detectCurrentTrend(array $levels, float $threshold = self::VARIATION_THRESHOLD_CM): ?string
    {
        $significantDeltas = [];
        $count = count($levels);

        for ($i = 1; $i < $count; $i++) {
            $cur = $levels[$i];
            $prev = $levels[$i - 1];
            if ($cur === null || $prev === null || !is_numeric($cur) || !is_numeric($prev)) {
                continue;
            }
            $delta = (float) $cur - (float) $prev;
            if (abs($delta) <= $threshold) {
                continue;
            }
            $significantDeltas[] = $delta;
        }

        if ($significantDeltas === []) {
            return null;
        }

        $recent = array_slice($significantDeltas, -3);
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
        $frequency = ($totalHours > 0 && $cycles > 0) ? $cycles / $totalHours : null;

        $frequencyStddev = null;
        if (count($cycleDurations) > 1) {
            $cycleFrequencies = array_map(static function ($duration) {
                return $duration > 0 ? 1 / $duration : 0;
            }, $cycleDurations);

            $frequencyStddev = \App\Util\MathUtils::standardDeviation($cycleFrequencies);
        }

        return [
            'frequency' => $frequency,
            'frequencyStddev' => $frequencyStddev,
        ];
    }

    /**
     * @param array<float> $amplitudes Amplitudes des cycles
     * @return array{marnage: float|null, marnageStddev: float|null}
     */
    public function computeMarnageStats(array $amplitudes): array
    {
        $cycles = count($amplitudes);

        return [
            'marnage' => $cycles > 0 ? array_sum($amplitudes) / $cycles : null,
            'marnageStddev' => $cycles > 1 ? \App\Util\MathUtils::standardDeviation($amplitudes) : null,
        ];
    }

    /**
     * @param array<float|int|null> $levels Niveaux d'eau
     * @return array{positive: float, negative: float, global: float}
     */
    public function computeVariations(array $levels, float $threshold = 1.0): array
    {
        $count = count($levels);
        if ($count < 2) {
            return ['positive' => 0.0, 'negative' => 0.0, 'global' => 0.0];
        }

        $positive = 0.0;
        $negative = 0.0;

        for ($i = 1; $i < $count; $i++) {
            $cur = $levels[$i];
            $prev = $levels[$i - 1];
            if ($cur === null || $prev === null || !is_numeric($cur) || !is_numeric($prev)) {
                continue;
            }
            $delta = (float) $cur - (float) $prev;

            if (abs($delta) <= $threshold) {
                continue;
            }

            if ($delta > 0) {
                $positive += $delta;
            } elseif ($delta < 0) {
                $negative += abs($delta);
            }
        }

        $first = $levels[0];
        $last = $levels[$count - 1];
        $global = 0.0;
        if ($first !== null && $last !== null && is_numeric($first) && is_numeric($last)) {
            $global = (float) $last - (float) $first;
        }

        return [
            'positive' => $positive,
            'negative' => $negative,
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
        $points = [];
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
            $points[] = ['ts' => $tsMs, 'v' => (float) $level];
        }

        if (count($points) < 3) {
            return ['peaks' => [], 'troughs' => []];
        }

        $trend = 0;
        $extremeIdx = 0;
        $lastEventTs = null;
        $minIntervalMs = max(0, $minIntervalSeconds) * 1000;

        $peaks = [];
        $troughs = [];
        $n = count($points);

        for ($i = 1; $i < $n; $i++) {
            if ($trend === 1 && $points[$i]['v'] >= $points[$extremeIdx]['v']) {
                $extremeIdx = $i;
            } elseif ($trend === -1 && $points[$i]['v'] <= $points[$extremeIdx]['v']) {
                $extremeIdx = $i;
            }

            $delta = $points[$i]['v'] - $points[$i - 1]['v'];
            if (abs($delta) <= $threshold) {
                continue;
            }
            $dir = $delta > 0 ? 1 : -1;

            if ($trend === 0) {
                $trend = $dir;
                $extremeIdx = $i;
                continue;
            }

            if ($trend === 1) {
                if ($points[$i]['v'] >= $points[$extremeIdx]['v']) {
                    continue;
                }
                $drop = $points[$extremeIdx]['v'] - $points[$i]['v'];
                if ($drop >= $threshold) {
                    $this->recordExtremum($peaks, $points, $extremeIdx, $lastEventTs, $minIntervalMs);
                    $trend = -1;
                    $extremeIdx = $i;
                }
                continue;
            }

            if ($points[$i]['v'] <= $points[$extremeIdx]['v']) {
                continue;
            }
            $rise = $points[$i]['v'] - $points[$extremeIdx]['v'];
            if ($rise >= $threshold) {
                $this->recordExtremum($troughs, $points, $extremeIdx, $lastEventTs, $minIntervalMs);
                $trend = 1;
                $extremeIdx = $i;
            }
        }

        if ($trend === 1) {
            $this->recordExtremum($peaks, $points, $extremeIdx, $lastEventTs, $minIntervalMs);
        } elseif ($trend === -1) {
            $this->recordExtremum($troughs, $points, $extremeIdx, $lastEventTs, $minIntervalMs);
        }

        return ['peaks' => $peaks, 'troughs' => $troughs];
    }

    /**
     * @param array<array{0:int,1:float}> $target
     * @param array<array{ts:int,v:float}> $points
     */
    private function recordExtremum(
        array &$target,
        array $points,
        int $extremeIdx,
        ?int &$lastEventTs,
        int $minIntervalMs
    ): void {
        $eventTs = $points[$extremeIdx]['ts'];
        if ($lastEventTs !== null && ($eventTs - $lastEventTs) < $minIntervalMs) {
            return;
        }
        $target[] = [$eventTs, $points[$extremeIdx]['v']];
        $lastEventTs = $eventTs;
    }
}
