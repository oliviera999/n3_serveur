<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Service de détection des cycles de marée (pompage) dans les niveaux d'eau.
 * 
 * Analyse les variations de niveau pour identifier les cycles complets
 * (montée + descente) et calcule les amplitudes et durées.
 */
class TideCycleDetector
{
    /** Variation minimale (cm) pour être considérée comme significative */
    private const VARIATION_THRESHOLD = 2.0;

    /**
     * Détecte les cycles de marée dans une série de niveaux d'eau
     *
     * Un cycle complet est défini par une séquence montée + descente (ou l'inverse).
     * Les variations inférieures au seuil sont ignorées pour filtrer le bruit.
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
    public function detectCycles(array $levels, array $times, float $threshold = self::VARIATION_THRESHOLD): array
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
        $direction = null; // 1 = montée, -1 = descente
        $hasRisen = false; // A-t-on eu une montée dans le cycle actuel
        $hasFallen = false; // A-t-on eu une descente dans le cycle actuel
        $amplitudes = []; // Marnages de chaque cycle
        $cycleDurations = []; // Durées de chaque cycle (en heures)
        $cycleStartTime = $times[0];

        for ($i = 1; $i < $count; $i++) {
            $delta = $levels[$i] - $levels[$i - 1];
            
            // Ignorer les variations inférieures au seuil
            if (abs($delta) <= $threshold) {
                continue;
            }

            $currentDir = $delta > 0 ? 1 : -1;

            if ($direction === null) {
                $direction = $currentDir;
                $hasRisen = ($currentDir === 1);
                $hasFallen = ($currentDir === -1);
            }

            // Suivre les mouvements dans le cycle actuel
            if ($currentDir === 1) {
                $hasRisen = true;
            } else {
                $hasFallen = true;
            }

            // Changement de direction ET cycle complet (montée + descente) => cycle complet détecté
            if ($direction !== $currentDir && $hasRisen && $hasFallen) {
                // Enregistrer l'amplitude du cycle complet
                $amplitude = $cycleMax - $cycleMin;
                $amplitudes[] = $amplitude;

                // Enregistrer la durée du cycle complet (en heures)
                $cycleEndTime = $times[$i - 1];
                $startTimestamp = strtotime($cycleStartTime);
                $endTimestamp = strtotime($cycleEndTime);
                
                if ($startTimestamp !== false && $endTimestamp !== false) {
                    $cycleDuration = ($endTimestamp - $startTimestamp) / 3600;
                    if ($cycleDuration > 0) {
                        $cycleDurations[] = $cycleDuration;
                    }
                }

                // Reset pour nouveau cycle
                $cycleMin = $levels[$i - 1];
                $cycleMax = $levels[$i - 1];
                $direction = $currentDir;
                $hasRisen = ($currentDir === 1);
                $hasFallen = ($currentDir === -1);
                $cycleStartTime = $times[$i - 1];
            } elseif ($direction !== $currentDir) {
                // Changement de direction mais cycle pas encore complet, on continue
                $direction = $currentDir;
            }

            // Mettre à jour min/max du cycle courant
            $cycleMin = min($cycleMin, $levels[$i]);
            $cycleMax = max($cycleMax, $levels[$i]);
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
     * Calcule les statistiques de fréquence à partir des durées de cycles
     *
     * @param array<float> $cycleDurations Durées des cycles en heures
     * @param int $cycles Nombre de cycles
     * @param float $totalHours Durée totale de la période analysée
     * @return array{frequency: float|null, frequencyStddev: float|null}
     */
    public function computeFrequencyStats(array $cycleDurations, int $cycles, float $totalHours): array
    {
        // Fréquence globale (cycles par heure)
        $frequency = ($totalHours > 0 && $cycles > 0) ? $cycles / $totalHours : null;

        // Écart-type de la fréquence (basé sur les durées de cycles)
        $frequencyStddev = null;
        if (count($cycleDurations) > 1) {
            // Calculer la fréquence pour chaque cycle (1/durée)
            $cycleFrequencies = array_map(function($duration) {
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
     * Calcule le marnage moyen et son écart-type
     *
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
     * Calcule les variations cumulées (montées et descentes séparées)
     *
     * @param array<float|int|null> $levels Niveaux d'eau
     * @param float $threshold Seuil minimum pour compter une variation
     * @return array{positive: float, negative: float, global: float}
     */
    public function computeVariations(array $levels, float $threshold = 1.0): array
    {
        $count = count($levels);
        if ($count < 2) {
            return ['positive' => 0.0, 'negative' => 0.0, 'global' => 0.0];
        }

        $positive = 0.0; // Montées cumulées
        $negative = 0.0; // Descentes cumulées (valeur absolue)

        for ($i = 1; $i < $count; $i++) {
            $delta = $levels[$i] - $levels[$i - 1];
            
            // Ignorer les variations d'incertitude
            if (abs($delta) <= $threshold) {
                continue;
            }

            if ($delta > 0) {
                $positive += $delta;
            } elseif ($delta < 0) {
                $negative += abs($delta);
            }
        }

        $global = $levels[$count - 1] - $levels[0];

        return [
            'positive' => $positive,
            'negative' => $negative,
            'global' => $global,
        ];
    }
}
