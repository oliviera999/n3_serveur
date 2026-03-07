<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SensorReadRepository;
use DateTimeInterface;

/**
 * Analyse avancée des cycles de marée (pompage) sur les niveaux d'eau.
 *
 * - Marnage moyen : moyenne des amplitudes (max-min) pour chaque cycle complet.
 * - Fréquence des marées : nombre de cycles complets par heure.
 */
class TideAnalysisService
{
    public function __construct(private SensorReadRepository $repo) {}

    /**
     * Retourne un tableau associatif avec les statistiques « marnage_moyen » et « frequence_marees ».
     * @param DateTimeInterface|string $start
     * @param DateTimeInterface|string $end
     */
    public function compute(DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        // On récupère les lectures dans l'ordre chronologique ASC
        $rows = $this->repo->fetchBetween($start, $end);
        if ($rows === []) {
            return [
                'marnage_moyen'    => null,
                'frequence_marees' => null,
                'cycles'           => 0,
            ];
        }

        // fetchBetween renvoie DESC → inverser pour ASC
        $rows = array_reverse($rows);

        $levels = array_column($rows, 'EauAquarium'); // ou EauReserve
        $times  = array_column($rows, 'reading_time');

        $cycleMin = $levels[0];
        $cycleMax = $levels[0];
        $direction = null; // 1 = montée, -1 = descente
        $amplitudes = [];

        for ($i = 1, $len = count($levels); $i < $len; $i++) {
            $delta = $levels[$i] - $levels[$i - 1];
            $currentDir = $delta > 0 ? 1 : ($delta < 0 ? -1 : $direction);

            if ($direction === null) {
                $direction = $currentDir;
            }

            // Changement de direction => cycle complet
            if ($currentDir !== 0 && $direction !== $currentDir) {
                // Fin de cycle précédent, on calcule amplitude
                $amplitudes[] = $cycleMax - $cycleMin;
                // Reset pour nouveau cycle
                $cycleMin = $levels[$i - 1];
                $cycleMax = $levels[$i - 1];
                $direction = $currentDir;
            }
            // Mettre à jour min/max du cycle courant
            $cycleMin = min($cycleMin, $levels[$i]);
            $cycleMax = max($cycleMax, $levels[$i]);
        }

        // Nombre de cycles complets détectés
        $cycles = count($amplitudes);
        $averageRange = $cycles > 0 ? array_sum($amplitudes) / $cycles : null;

        // Fréquence : cycles / durée (heures)
        $durationSeconds = strtotime(end($times)) - strtotime($times[0]);
        $hours = $durationSeconds / 3600;
        $frequency = ($hours > 0 && $cycles > 0) ? $cycles / $hours : null;

        // ------------------------------------------------------------
        // Variations sur EauReserve
        // ------------------------------------------------------------
        $reserveLevels = array_column($rows, 'EauReserve');
        $reservePos = 0.0; // montées cumulées
        $reserveNeg = 0.0; // descentes cumulées (valeur absolue)
        for ($i = 1, $len = count($reserveLevels); $i < $len; $i++) {
            $d = $reserveLevels[$i] - $reserveLevels[$i - 1];
            if ($d > 0) {
                $reservePos += $d;
            } elseif ($d < 0) {
                $reserveNeg += abs($d);
            }
        }
        $reserveGlobal = $reserveLevels[$len - 1] - $reserveLevels[0];

        return [
            'marnage_moyen'    => $averageRange,
            'frequence_marees' => $frequency,
            'cycles'           => $cycles,
            'reserve_pos'      => $reservePos,
            'reserve_neg'      => $reserveNeg,
            'reserve_var'      => $reserveGlobal,
        ];
    }

    public function computeWeeklySeries(DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        // Convert parameters to DateTime objects for easier manipulation
        $currentStart = $start instanceof DateTimeInterface ? (clone $start) : new \DateTime($start);
        $overallEnd   = $end   instanceof DateTimeInterface   ? (clone $end)   : new \DateTime($end);

        // Normalise times to start of day for the first week boundary
        $currentStart->setTime(0, 0, 0);
        $overallEnd->setTime(23, 59, 59);

        $labels        = [];
        $marnages      = [];
        $frequences    = [];
        $reservePosArr = [];
        $reserveNegArr = [];
        $reserveVarArr = [];

        while ($currentStart <= $overallEnd) {
            // Calculate end of the current week (Sunday 23:59:59)
            $currentEnd = (clone $currentStart)->modify('+6 days')->setTime(23, 59, 59);
            if ($currentEnd > $overallEnd) {
                $currentEnd = $overallEnd;
            }

            $stats = $this->compute($currentStart, $currentEnd);

            // Label uses ISO week number and year (e.g. 2023-W15)
            $labels[]        = $currentStart->format('o-\WW');
            $marnages[]      = $stats['marnage_moyen'];
            $frequences[]    = $stats['frequence_marees'];
            $reservePosArr[] = $stats['reserve_pos'];
            $reserveNegArr[] = $stats['reserve_neg'];
            $reserveVarArr[] = $stats['reserve_var'];

            // Move to next week
            $currentStart = (clone $currentStart)->modify('+7 days');
        }

        return [
            'labels'            => $labels,
            'marnage_moyen'     => $marnages,
            'frequence_marees'  => $frequences,
            'reserve_pos'       => $reservePosArr,
            'reserve_neg'       => $reserveNegArr,
            'reserve_var'       => $reserveVarArr,
        ];
    }
} 