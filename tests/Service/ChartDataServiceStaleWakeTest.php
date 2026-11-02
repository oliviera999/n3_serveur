<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ChartDataService;
use PHPUnit\Framework\TestCase;

/**
 * Relevés « réchauffés » au réveil (6.50.0) : le 1er POST après ~30 min de veille
 * reprend la valeur d'avant la veille ; omis du tracé pour éviter l'effet d'escalier.
 * Données inspirées du FFP3 en production (rafales de mesures toutes les 30 s puis veille).
 */
final class ChartDataServiceStaleWakeTest extends TestCase
{
    private const MIN = 60000;

    public function testRepeatedValueRightAfterSleepIsFlaggedPerColumn(): void
    {
        $t0 = 1793600000000;
        $series = [
            'reading_time' => [$t0, $t0 + self::MIN, $t0 + 34 * self::MIN, $t0 + 35 * self::MIN, $t0 + 36 * self::MIN],
            // TempAir : réveil avec l'ancienne valeur (22.3) puis vraie mesure (21.9) → index 2 omis.
            'TempAir' => [22.2, 22.3, 22.3, 21.9, 21.8],
            // TempEau : relue dès le réveil (valeur différente) → rien n'est omis.
            'TempEau' => [21.2, 21.2, 21.0, 21.0, 21.0],
            // Valeur nulle au réveil : jamais signalée.
            'EauAquarium' => [8.1, 8.1, null, null, null],
        ];

        $stale = ChartDataService::staleWakeIndexes($series, ['TempAir', 'TempEau', 'EauAquarium']);

        $this->assertSame(['TempAir' => [2]], $stale);
    }

    public function testShortGapsAndTheLastPointAreKept(): void
    {
        $t0 = 1793600000000;
        $series = [
            'reading_time' => [$t0, $t0 + self::MIN, $t0 + 2 * self::MIN, $t0 + 40 * self::MIN],
            // Valeurs égales sans veille (écart 1 min) : conservées ; dernier point (direct) conservé.
            'Humidite' => [74.0, 74.0, 74.0, 74.0],
        ];

        $this->assertSame([], ChartDataService::staleWakeIndexes($series, ['Humidite']));
    }

    public function testFirstColumnWithDataSkipsSilentSensors(): void
    {
        $readings = [
            ['EauAquarium' => null, 'EauReserve' => '410', 'TempEau' => 21.0],
            ['EauAquarium' => '', 'EauReserve' => '412', 'TempEau' => 21.1],
        ];

        $this->assertSame('EauReserve', ChartDataService::firstColumnWithData($readings, ['EauAquarium', 'EauReserve', 'TempEau']));
        $this->assertNull(ChartDataService::firstColumnWithData($readings, ['EauAquarium']));
    }
}
