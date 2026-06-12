<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\SensorReadRepository;
use App\Service\TideAnalysisService;
use App\Service\TideCycleDetector;
use App\Util\Ffp3WaterLevelUnit;
use PHPUnit\Framework\TestCase;

/**
 * Couvre l'extraction de computeFromRows et la réécriture de computeWeeklySeries
 * (une seule récupération + découpage hebdomadaire en PHP).
 */
class TideAnalysisServiceTest extends TestCase
{
    /**
     * Génère une série brute (mm) d'oscillations EauAquarium sur plusieurs jours.
     *
     * @return array<array<string, mixed>> Lignes ASC, valeurs en mm (brut firmware)
     */
    private function makeRows(string $startSql, int $count, int $stepMinutes): array
    {
        $rows = [];
        $base = strtotime($startSql);
        // Oscillation triangulaire d'amplitude ~80 mm (8 cm) pour générer des cycles.
        $profile = array_merge(
            range(300.0, 380.0, 20.0),
            range(360.0, 300.0, 20.0)
        );
        for ($i = 0; $i < $count; $i++) {
            $level = $profile[$i % count($profile)];
            $rows[] = [
                'EauAquarium' => $level,
                'EauReserve' => 500.0 - $i, // dérive lente de la réserve
                'diffMaree' => 12.0 + ($i % 3),
                'reading_time' => date('Y-m-d H:i:s', $base + $i * $stepMinutes * 60),
            ];
        }

        return $rows;
    }

    public function testComputeFromRowsEmptyReturnsNullStats(): void
    {
        $repo = $this->createMock(SensorReadRepository::class);
        $service = new TideAnalysisService($repo, new TideCycleDetector());

        $result = $service->computeFromRows([]);

        $this->assertNull($result['marnage_moyen']);
        $this->assertNull($result['frequence_marees']);
        $this->assertSame(0, $result['cycles']);
        $this->assertSame(0.0, $result['reserve_pos']);
        $this->assertNull($result['diff_maree']['moyenne']);
    }

    public function testComputeDelegatesToComputeFromRows(): void
    {
        // fetchBetween renvoie DESC ; compute() doit reverse + scaler avant computeFromRows.
        $rowsMmAsc = $this->makeRows('2026-05-25 00:00:00', 40, 30);
        $rowsMmDesc = array_reverse($rowsMmAsc);

        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('fetchBetween')->willReturn($rowsMmDesc);

        $service = new TideAnalysisService($repo, new TideCycleDetector());
        $viaCompute = $service->compute('2026-05-25 00:00:00', '2026-05-25 23:59:59');

        // Référence : computeFromRows alimenté manuellement avec ASC + cm.
        $rowsCmAsc = Ffp3WaterLevelUnit::scaleSensorRowsFromMmToCm($rowsMmAsc);
        $reference = $service->computeFromRows($rowsCmAsc);

        $this->assertEquals($reference, $viaCompute);
    }

    public function testComputeWeeklySeriesSingleFetchAndEquivalentSplit(): void
    {
        // 3 semaines de données à intervalle de 30 minutes.
        $startSql = '2026-05-04 00:00:00'; // un lundi
        $endSql = '2026-05-24 23:59:59';
        $rowsMmAsc = $this->makeRows($startSql, 3 * 7 * 48, 30);

        // Le repository ne doit être interrogé qu'UNE fois (fetchTideSeriesBetween),
        // jamais via fetchBetween (l'ancienne implémentation faisait 1 fetch/semaine).
        $repo = $this->createMock(SensorReadRepository::class);
        $repo->expects($this->once())
            ->method('fetchTideSeriesBetween')
            ->willReturn($rowsMmAsc);
        $repo->expects($this->never())->method('fetchBetween');

        $service = new TideAnalysisService($repo, new TideCycleDetector());
        $weekly = $service->computeWeeklySeries($startSql, $endSql);

        // 3 semaines attendues.
        $this->assertCount(3, $weekly['labels']);
        $this->assertSame('2026-W19', $weekly['labels'][0]);

        // Vérifie l'équivalence du découpage : on recompose manuellement chaque semaine
        // par filtrage des bornes [lundi 00:00 ; dimanche 23:59:59] et compare via computeFromRows.
        $rowsCmAsc = Ffp3WaterLevelUnit::scaleSensorRowsFromMmToCm($rowsMmAsc);
        $weekStart = new \DateTime($startSql);
        $weekStart->setTime(0, 0, 0);
        for ($w = 0; $w < 3; $w++) {
            $weekEnd = (clone $weekStart)->modify('+6 days')->setTime(23, 59, 59);
            $startStr = $weekStart->format('Y-m-d H:i:s');
            $endStr = $weekEnd->format('Y-m-d H:i:s');
            $weekRows = array_values(array_filter(
                $rowsCmAsc,
                static fn (array $r): bool => $r['reading_time'] >= $startStr && $r['reading_time'] <= $endStr
            ));
            $expected = $service->computeFromRows($weekRows);

            $this->assertEqualsWithDelta(
                $expected['marnage_moyen'],
                $weekly['marnage_moyen'][$w],
                0.0001,
                "marnage semaine $w"
            );
            $this->assertEqualsWithDelta(
                $expected['reserve_neg'],
                $weekly['reserve_neg'][$w],
                0.0001,
                "reserve_neg semaine $w"
            );
            $this->assertEqualsWithDelta(
                $expected['diff_maree']['moyenne'],
                $weekly['diff_maree_moyenne'][$w],
                0.0001,
                "diff_maree semaine $w"
            );

            $weekStart = (clone $weekStart)->modify('+7 days');
        }
    }

    public function testComputeWeeklySeriesEmptyRange(): void
    {
        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('fetchTideSeriesBetween')->willReturn([]);

        $service = new TideAnalysisService($repo, new TideCycleDetector());
        $weekly = $service->computeWeeklySeries('2026-05-04 00:00:00', '2026-05-10 23:59:59');

        $this->assertCount(1, $weekly['labels']);
        $this->assertNull($weekly['marnage_moyen'][0]);
        $this->assertNull($weekly['frequence_marees'][0]);
        $this->assertSame(0.0, $weekly['reserve_pos'][0]);
    }
}
