<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SensorReadRepository;
use App\Util\MathUtils;
use DateTimeInterface;

/**
 * Service d'analyse du bilan hydrique du système aquaponique.
 * 
 * Calcule les statistiques avancées sur la consommation, le ravitaillement,
 * les marées et le marnage avec filtrage des incertitudes de mesure.
 */
class WaterBalanceService
{
    private const UNCERTAINTY_THRESHOLD = 1.0; // Variations ≤1 cm considérées comme incertitudes
    private const TIDE_VARIATION_THRESHOLD = 2.0; // Variations ≤2 cm ignorées pour la détection des cycles de marée

    public function __construct(
        private SensorReadRepository $repo,
        private TideCycleDetector $cycleDetector
    ) {}

    /**
     * Calcule le bilan hydrique complet sur une période donnée.
     * 
     * @param DateTimeInterface|string $start Date de début
     * @param DateTimeInterface|string $end Date de fin
     * @return array Statistiques complètes du bilan hydrique
     */
    public function computeBalance(DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        $rows = $this->repo->fetchBetween($start, $end);
        if ($rows === []) {
            return $this->getEmptyBalance();
        }

        // fetchBetween renvoie DESC → inverser pour ASC (chronologique)
        $rows = array_reverse($rows);

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
            
            // Aquarium - Consommation
            'aquarium_consumption' => $aquariumConsumption,
        ];
    }

    /**
     * Calcule les statistiques de la réserve (consommation, ravitaillement, bilan)
     */
    private function computeReserveStats(array $rows): array
    {
        $reserveLevels = array_column($rows, 'EauReserve');
        $variations = $this->cycleDetector->computeVariations($reserveLevels, self::UNCERTAINTY_THRESHOLD);

        return [
            'consumption' => $variations['negative'],
            'refill' => $variations['positive'],
            'balance' => $variations['positive'] - $variations['negative'],
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
            ];
        }

        // Utiliser le détecteur de cycles
        $cycleData = $this->cycleDetector->detectCycles($levels, $times, self::TIDE_VARIATION_THRESHOLD);
        $cycles = $cycleData['cycles'];
        $amplitudes = $cycleData['amplitudes'];
        $cycleDurations = $cycleData['cycleDurations'];

        // Marnage moyen et écart-type
        $marnageStats = $this->cycleDetector->computeMarnageStats($amplitudes);

        // Fréquence des marées (nombre par heure)
        $durationSeconds = strtotime(end($times)) - strtotime($times[0]);
        $totalHours = $durationSeconds / 3600;
        $frequencyStats = $this->cycleDetector->computeFrequencyStats($cycleDurations, $cycles, $totalHours);

        return [
            'frequency' => $frequencyStats['frequency'],
            'frequency_stddev' => $frequencyStats['frequencyStddev'],
            'marnage' => $marnageStats['marnage'],
            'marnage_stddev' => $marnageStats['marnageStddev'],
            'cycles' => $cycles,
        ];
    }

    /**
     * Calcule la consommation moyenne de l'aquarium (différence de niveau)
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

            // Compter uniquement les baisses de niveau (consommation)
            if ($delta < 0) {
                $consumption += abs($delta);
                $countSignificantChanges++;
            }
        }

        return $countSignificantChanges > 0 ? $consumption / $countSignificantChanges : null;
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
            'aquarium_consumption' => null,
        ];
    }
}
