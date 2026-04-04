<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\Ffp3WaterLevelUnit;
use PHPUnit\Framework\TestCase;

class Ffp3WaterLevelUnitTest extends TestCase
{
    public function testScaleSensorRowFromMmToCm(): void
    {
        $row = [
            'EauAquarium' => '125',
            'EauReserve' => 200.0,
            'EauPotager' => null,
            'TempAir' => 22.5,
        ];
        $out = Ffp3WaterLevelUnit::scaleSensorRowFromMmToCm($row);
        $this->assertSame(12.5, $out['EauAquarium']);
        $this->assertSame(20.0, $out['EauReserve']);
        $this->assertNull($out['EauPotager']);
        $this->assertSame(22.5, $out['TempAir']);
    }

    public function testScaleAquaponieFlattenedStatsFromMmToCm(): void
    {
        $flat = [
            'min_eauaqua' => 100,
            'avg_eaureserve' => 50,
            'stddev_eaupota' => 5,
            'min_tempair' => 20,
        ];
        $out = Ffp3WaterLevelUnit::scaleAquaponieFlattenedStatsFromMmToCm($flat);
        $this->assertSame(10.0, $out['min_eauaqua']);
        $this->assertSame(5.0, $out['avg_eaureserve']);
        $this->assertSame(0.5, $out['stddev_eaupota']);
        $this->assertSame(20, $out['min_tempair']);
    }

    public function testScaleDashboardWaterStatsFromMmToCm(): void
    {
        $stats = [
            'EauAquarium' => ['min' => 100.0, 'max' => 200.0, 'avg' => 150.0, 'stddev' => 10.0],
            'TempAir' => ['min' => 18.0, 'max' => 25.0, 'avg' => 21.0, 'stddev' => 1.0],
        ];
        $out = Ffp3WaterLevelUnit::scaleDashboardWaterStatsFromMmToCm($stats);
        $this->assertSame(10.0, $out['EauAquarium']['min']);
        $this->assertSame(20.0, $out['EauAquarium']['max']);
        $this->assertSame(15.0, $out['EauAquarium']['avg']);
        $this->assertSame(1.0, $out['EauAquarium']['stddev']);
        $this->assertSame(18.0, $out['TempAir']['min']);
    }
}
