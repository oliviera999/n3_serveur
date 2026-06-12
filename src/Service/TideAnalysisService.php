<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SensorReadRepository;
use App\Util\Ffp3WaterLevelUnit;
use App\Util\MathUtils;
use App\Util\ReadingTimeParser;
use DateTimeInterface;

/**
 * Analyse avancée des cycles de marée (pompage) sur les niveaux d'eau.
 *
 * - Marnage moyen : moyenne des amplitudes (max-min) pour chaque cycle complet.
 * - Fréquence des marées : nombre de cycles complets par heure.
 */
class TideAnalysisService
{
    private const RESERVE_VARIATION_THRESHOLD_CM = 1.0;

    public function __construct(
        private SensorReadRepository $repo,
        private TideCycleDetector $cycleDetector
    ) {
    }

    /**
     * Retourne un tableau associatif avec les statistiques « marnage_moyen » et « frequence_marees ».
     * @param DateTimeInterface|string $start
     * @param DateTimeInterface|string $end
     */
    public function compute(DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        // On récupère les lectures puis on délègue à computeFromRows.
        // fetchBetween renvoie DESC → inverser pour ASC + conversion mm→cm.
        $rows = $this->repo->fetchBetween($start, $end);
        $rows = array_reverse($rows);
        $rows = Ffp3WaterLevelUnit::scaleSensorRowsFromMmToCm($rows);

        return $this->computeFromRows($rows);
    }

    /**
     * Calcule les statistiques de marée à partir de lignes déjà préparées.
     *
     * @param array<array<string, mixed>> $rowsAsc Lignes en ordre chronologique ASC,
     *        déjà converties mm→cm.
     */
    public function computeFromRows(array $rowsAsc): array
    {
        if ($rowsAsc === []) {
            return [
                'marnage_moyen' => null,
                'frequence_marees' => null,
                'cycles' => 0,
                'reserve_pos' => 0.0,
                'reserve_neg' => 0.0,
                'reserve_var' => 0.0,
                'diff_maree' => [
                    'moyenne' => null,
                    'min' => null,
                    'max' => null,
                    'ecart_type' => null,
                ],
            ];
        }

        $rows = $rowsAsc;
        $levels = array_column($rows, 'EauAquarium');
        $times = array_column($rows, 'reading_time');

        // Utiliser le détecteur de cycles
        $cycleData = $this->cycleDetector->detectCycles($levels, $times);
        $cycles = $cycleData['cycles'];
        $amplitudes = $cycleData['amplitudes'];

        // Marnage moyen
        $averageRange = $cycles > 0 ? array_sum($amplitudes) / $cycles : null;

        $startTs = ReadingTimeParser::toUnixSeconds((string) $times[0]);
        $endTs = ReadingTimeParser::toUnixSeconds((string) end($times));
        $hours = ($startTs !== null && $endTs !== null && $endTs > $startTs)
            ? ($endTs - $startTs) / 3600
            : 0.0;
        $frequency = ($hours > 0 && $cycles > 0) ? $cycles / $hours : null;

        // ------------------------------------------------------------
        // Variations sur EauReserve
        // ------------------------------------------------------------
        $reserveLevels = array_column($rows, 'EauReserve');
        $reserveVariations = $this->cycleDetector->computeVariations(
            $reserveLevels,
            self::RESERVE_VARIATION_THRESHOLD_CM
        );

        // diffMaree : variation de distance aquarium (mm, brut firmware) sur fenêtre temporelle
        $diffMareeLevels = array_column($rows, 'diffMaree');
        $diffMareeValid = MathUtils::filterValid($diffMareeLevels);

        $diffMareeStats = [
            'moyenne' => MathUtils::mean($diffMareeValid),
            'min' => MathUtils::min($diffMareeValid),
            'max' => MathUtils::max($diffMareeValid),
            'ecart_type' => MathUtils::standardDeviation($diffMareeValid),
        ];

        return [
            'marnage_moyen' => $averageRange,
            'frequence_marees' => $frequency,
            'cycles' => $cycles,
            'reserve_pos' => $reserveVariations['positive'],
            'reserve_neg' => $reserveVariations['negative'],
            'reserve_var' => $reserveVariations['global'],
            'diff_maree' => $diffMareeStats,
        ];
    }

    public function computeWeeklySeries(DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        // Convert parameters to DateTime objects for easier manipulation
        $currentStart = $start instanceof DateTimeInterface ? (clone $start) : new \DateTime($start);
        $overallEnd = $end   instanceof DateTimeInterface ? (clone $end) : new \DateTime($end);

        // Normalise times to start of day for the first week boundary
        $currentStart->setTime(0, 0, 0);
        $overallEnd->setTime(23, 59, 59);

        // UNE seule récupération de la plage entière (4 colonnes, ASC), convertie mm→cm une fois.
        // Les lignes sont ensuite découpées par semaine en PHP au lieu d'une requête SQL par semaine.
        $allRows = Ffp3WaterLevelUnit::scaleSensorRowsFromMmToCm(
            $this->repo->fetchTideSeriesBetween($currentStart, $overallEnd)
        );

        $labels = [];
        $marnages = [];
        $frequences = [];
        $reservePosArr = [];
        $reserveNegArr = [];
        $reserveVarArr = [];
        $diffMareeMoyenneArr = [];
        $diffMareeMinArr = [];
        $diffMareeMaxArr = [];

        // Curseur sur $allRows (triées ASC) : chaque ligne n'est visitée qu'une fois,
        // le découpage hebdomadaire complet reste en O(n).
        $rowCount = count($allRows);
        $cursor = 0;

        while ($currentStart <= $overallEnd) {
            // Calculate end of the current week (Sunday 23:59:59)
            $currentEnd = (clone $currentStart)->modify('+6 days')->setTime(23, 59, 59);
            if ($currentEnd > $overallEnd) {
                $currentEnd = $overallEnd;
            }

            // Découpage en PHP : on ne garde que les lignes de la semaine courante.
            $weekStartSql = $currentStart->format('Y-m-d H:i:s');
            $weekEndSql = $currentEnd->format('Y-m-d H:i:s');

            while ($cursor < $rowCount && (string) ($allRows[$cursor]['reading_time'] ?? '') < $weekStartSql) {
                $cursor++;
            }
            $weekRows = [];
            while ($cursor < $rowCount && (string) ($allRows[$cursor]['reading_time'] ?? '') <= $weekEndSql) {
                $weekRows[] = $allRows[$cursor];
                $cursor++;
            }

            $stats = $this->computeFromRows($weekRows);

            // Label uses ISO week number and year (e.g. 2023-W15)
            $labels[] = $currentStart->format('o-\WW');
            $marnages[] = $stats['marnage_moyen'];
            $frequences[] = $stats['frequence_marees'];
            $reservePosArr[] = $stats['reserve_pos'];
            $reserveNegArr[] = $stats['reserve_neg'];
            $reserveVarArr[] = $stats['reserve_var'];
            $diffMareeMoyenneArr[] = $stats['diff_maree']['moyenne'];
            $diffMareeMinArr[] = $stats['diff_maree']['min'];
            $diffMareeMaxArr[] = $stats['diff_maree']['max'];

            // Move to next week
            $currentStart = (clone $currentStart)->modify('+7 days');
        }

        return [
            'labels' => $labels,
            'marnage_moyen' => $marnages,
            'frequence_marees' => $frequences,
            'reserve_pos' => $reservePosArr,
            'reserve_neg' => $reserveNegArr,
            'reserve_var' => $reserveVarArr,
            'diff_maree_moyenne' => $diffMareeMoyenneArr,
            'diff_maree_min' => $diffMareeMinArr,
            'diff_maree_max' => $diffMareeMaxArr,
        ];
    }
}
