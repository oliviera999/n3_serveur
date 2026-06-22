<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SensorReadRepository;
use App\Util\Ffp3WaterLevelUnit;
use App\Util\MathUtils;
use App\Util\ReadingTimeParser;
use DateTimeInterface;

/**
 * Service d'analyse du bilan hydrique du système aquaponique.
 *
 * Calcule les statistiques avancées sur la consommation, le ravitaillement,
 * les marées et le marnage avec filtrage des incertitudes de mesure.
 */
class WaterBalanceService
{
    private const UNCERTAINTY_THRESHOLD = 1.0;

    public function __construct(
        private SensorReadRepository $repo,
        private TideCycleDetector $cycleDetector
    ) {
    }

    /**
     * Calcule le bilan hydrique complet sur une période donnée.
     *
     * @param DateTimeInterface|string $start Date de début
     * @param DateTimeInterface|string $end Date de fin
     * @param array<array<string, mixed>>|null $rowsAsc Lignes déjà chargées en ordre
     *        chronologique ASC ET converties mm→cm. Si null, le service les charge
     *        et les convertit lui-même (comportement historique).
     * @return array Statistiques complètes du bilan hydrique
     */
    public function computeBalance(
        DateTimeInterface|string $start,
        DateTimeInterface|string $end,
        ?array $rowsAsc = null
    ): array {
        if ($rowsAsc !== null) {
            // Lignes fournies par l'appelant : déjà ASC et déjà converties en cm.
            $rows = $rowsAsc;
        } else {
            $rows = $this->repo->fetchBetween($start, $end);
            // fetchBetween renvoie DESC → inverser pour ASC (chronologique)
            $rows = array_reverse($rows);
            $rows = Ffp3WaterLevelUnit::scaleSensorRowsFromMmToCm($rows);
        }

        if ($rows === []) {
            return $this->getEmptyBalance();
        }

        // ------------------------------------------------------------
        // RÉSERVE : Consommation et Ravitaillement
        // ------------------------------------------------------------
        $reserveStats = $this->computeReserveStats($rows);

        // ------------------------------------------------------------
        // AQUARIUM : Marées (fréquence et marnage)
        // ------------------------------------------------------------
        $tideStats = $this->computeTideStats($rows);

        // ------------------------------------------------------------
        // AQUARIUM : Consommation moyenne (différence)
        // ------------------------------------------------------------
        $aquariumConsumption = $this->computeAquariumConsumption($rows);

        // ------------------------------------------------------------
        // AQUARIUM : Consommation selon la courbe de tendance (cm/jour)
        // ------------------------------------------------------------
        $aquariumTrend = $this->computeAquariumTrendConsumption($rows);

        return [
            // Réserve
            'reserve_consumption' => $reserveStats['consumption'],
            'reserve_refill' => $reserveStats['refill'],
            'reserve_balance' => $reserveStats['balance'],

            // Aquarium - Marées
            'tide_frequency' => $tideStats['frequency'],
            'tide_frequency_stddev' => $tideStats['frequency_stddev'],
            'tide_marnage' => $tideStats['marnage'],
            'tide_marnage_stddev' => $tideStats['marnage_stddev'],
            'tide_cycles' => $tideStats['cycles'],
            'tide_trend' => $tideStats['trend'],
            'tide_trend_label' => $tideStats['trend_label'],
            'tide_threshold_cm' => TideCycleDetector::VARIATION_THRESHOLD_CM,

            // Aquarium - Consommation
            'aquarium_consumption' => $aquariumConsumption,

            // Aquarium - Consommation selon la courbe de tendance
            'aquarium_consumption_per_day' => $aquariumTrend['consumption_per_day'],
            'aquarium_trend_slope_per_day' => $aquariumTrend['slope_per_day'],
        ];
    }

    /**
     * Calcule les statistiques de la réserve (consommation, ravitaillement, bilan).
     *
     * Sémantique distance (EauReserve = distance capteur → surface) :
     * distance qui augmente (delta > 0) = niveau qui baisse = consommation ;
     * distance qui diminue (delta < 0) = niveau qui monte = ravitaillement.
     */
    private function computeReserveStats(array $rows): array
    {
        $reserveLevels = array_column($rows, 'EauReserve');
        $variations = $this->cycleDetector->computeVariations($reserveLevels, self::UNCERTAINTY_THRESHOLD);

        return [
            'consumption' => $variations['positive'],
            'refill' => $variations['negative'],
            'balance' => $variations['negative'] - $variations['positive'],
        ];
    }

    /**
     * Calcule les statistiques de marée de l'aquarium (fréquence, marnage, écarts-types)
     */
    private function computeTideStats(array $rows): array
    {
        $levels = array_column($rows, 'EauAquarium');
        $times = array_column($rows, 'reading_time');

        if (count($levels) < 2) {
            return [
                'frequency' => null,
                'frequency_stddev' => null,
                'marnage' => null,
                'marnage_stddev' => null,
                'cycles' => 0,
                'trend' => null,
                'trend_label' => null,
            ];
        }

        $threshold = TideCycleDetector::VARIATION_THRESHOLD_CM;
        // UNE seule passe zigzag partagée entre la détection de cycles et la tendance.
        $zigzag = $this->cycleDetector->analyzeSeries($levels, $threshold);
        $cycleData = $this->cycleDetector->detectCycles($levels, $times, $threshold, $zigzag);
        $cycles = $cycleData['cycles'];
        $amplitudes = $cycleData['amplitudes'];
        $cycleDurations = $cycleData['cycleDurations'];

        // Marnage moyen et écart-type
        $marnageStats = $this->cycleDetector->computeMarnageStats($amplitudes);

        $startTs = ReadingTimeParser::toUnixSeconds((string) $times[0]);
        $endTs = ReadingTimeParser::toUnixSeconds((string) end($times));
        $totalHours = ($startTs !== null && $endTs !== null && $endTs > $startTs)
            ? ($endTs - $startTs) / 3600
            : 0.0;
        $frequencyStats = $this->cycleDetector->computeFrequencyStats($cycleDurations, $cycles, $totalHours);

        $trend = $this->cycleDetector->detectCurrentTrend($levels, $threshold, $zigzag);

        return [
            'frequency' => $frequencyStats['frequency'],
            'frequency_stddev' => $frequencyStats['frequencyStddev'],
            'marnage' => $marnageStats['marnage'],
            'marnage_stddev' => $marnageStats['marnageStddev'],
            'cycles' => $cycles,
            'trend' => $trend,
            'trend_label' => TideCycleDetector::trendLabel($trend),
        ];
    }

    /**
     * Calcule la consommation moyenne de l'aquarium (cm par variation significative).
     *
     * Sémantique distance (EauAquarium = distance capteur → surface) :
     * seules les hausses de distance (delta > 0 = eau qui descend) comptent
     * comme consommation.
     */
    private function computeAquariumConsumption(array $rows): ?float
    {
        $levels = array_column($rows, 'EauAquarium');

        if (count($levels) < 2) {
            return null;
        }

        $consumption = 0.0;
        $countSignificantChanges = 0;

        for ($i = 1, $len = count($levels); $i < $len; $i++) {
            $delta = $levels[$i] - $levels[$i - 1];

            // Ignorer les variations d'incertitude
            if (abs($delta) <= self::UNCERTAINTY_THRESHOLD) {
                continue;
            }

            // Distance qui augmente = eau qui descend (consommation).
            if ($delta > 0) {
                $consumption += $delta;
                $countSignificantChanges++;
            }
        }

        return $countSignificantChanges > 0 ? $consumption / $countSignificantChanges : null;
    }

    /**
     * Calcule la consommation de l'aquarium d'après sa courbe de tendance.
     *
     * Ajuste une droite (régression par moindres carrés) sur le niveau
     * EauAquarium en fonction du temps, puis exprime sa pente en cm/jour.
     *
     * EauAquarium est une distance capteur → surface : une distance qui
     * augmente signifie que l'eau baisse. La pente de tendance positive
     * correspond donc à une consommation nette ; négative, à un gain net.
     *
     * @param array<array{reading_time?: mixed, EauAquarium?: mixed}> $rows Lectures ASC, niveaux en cm
     * @return array{consumption_per_day: float|null, slope_per_day: float|null}
     *         consumption_per_day = baisse moyenne du niveau en cm/jour (>= 0),
     *         slope_per_day = pente signée de la tendance en cm/jour.
     */
    private function computeAquariumTrendConsumption(array $rows): array
    {
        $xDays = [];
        $yLevels = [];
        $firstTs = null;

        foreach ($rows as $row) {
            $level = $row['EauAquarium'] ?? null;
            if ($level === null || !is_numeric($level)) {
                continue;
            }
            $ts = ReadingTimeParser::toUnixSeconds((string) ($row['reading_time'] ?? ''));
            if ($ts === null) {
                continue;
            }
            $firstTs ??= $ts;
            $xDays[] = ($ts - $firstTs) / 86400;
            $yLevels[] = (float) $level;
        }

        $regression = MathUtils::linearRegression($xDays, $yLevels);
        if ($regression === null) {
            return ['consumption_per_day' => null, 'slope_per_day' => null];
        }

        $slopePerDay = $regression['slope'];

        // Distance qui augmente (pente > 0) => l'eau baisse => consommation.
        return [
            'consumption_per_day' => max(0.0, $slopePerDay),
            'slope_per_day' => $slopePerDay,
        ];
    }

    /**
     * Retourne un bilan vide (aucune donnée disponible)
     */
    private function getEmptyBalance(): array
    {
        return [
            'reserve_consumption' => null,
            'reserve_refill' => null,
            'reserve_balance' => null,
            'tide_frequency' => null,
            'tide_frequency_stddev' => null,
            'tide_marnage' => null,
            'tide_marnage_stddev' => null,
            'tide_cycles' => 0,
            'tide_trend' => null,
            'tide_trend_label' => null,
            'tide_threshold_cm' => TideCycleDetector::VARIATION_THRESHOLD_CM,
            'aquarium_consumption' => null,
            'aquarium_consumption_per_day' => null,
            'aquarium_trend_slope_per_day' => null,
        ];
    }
}
