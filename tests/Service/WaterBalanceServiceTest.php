<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\SensorReadRepository;
use App\Service\TideCycleDetector;
use App\Service\WaterBalanceService;
use PHPUnit\Framework\TestCase;

class WaterBalanceServiceTest extends TestCase
{
    public function testComputeBalanceReturnsTideTrendAndStats(): void
    {
        $rows = [
            [
                'reading_time' => '2026-05-25 10:00:00',
                'EauAquarium' => 300,
                'EauReserve' => 500,
                'EauPotager' => 200,
            ],
            [
                'reading_time' => '2026-05-25 10:10:00',
                'EauAquarium' => 350,
                'EauReserve' => 490,
                'EauPotager' => 200,
            ],
            [
                'reading_time' => '2026-05-25 10:20:00',
                'EauAquarium' => 400,
                'EauReserve' => 480,
                'EauPotager' => 200,
            ],
            [
                'reading_time' => '2026-05-25 10:30:00',
                'EauAquarium' => 350,
                'EauReserve' => 470,
                'EauPotager' => 200,
            ],
            [
                'reading_time' => '2026-05-25 10:40:00',
                'EauAquarium' => 300,
                'EauReserve' => 460,
                'EauPotager' => 200,
            ],
            [
                'reading_time' => '2026-05-25 10:50:00',
                'EauAquarium' => 280,
                'EauReserve' => 450,
                'EauPotager' => 200,
            ],
        ];

        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('fetchBetween')->willReturn(array_reverse($rows));

        $service = new WaterBalanceService($repo, new TideCycleDetector());
        $balance = $service->computeBalance('2026-05-25 10:00:00', '2026-05-25 10:50:00');

        $this->assertArrayHasKey('tide_trend', $balance);
        $this->assertArrayHasKey('tide_trend_label', $balance);
        $this->assertArrayHasKey('tide_threshold_cm', $balance);
        $this->assertSame(2.0, $balance['tide_threshold_cm']);
        $this->assertGreaterThanOrEqual(0, $balance['tide_cycles']);
    }

    public function testComputeBalanceReportsTrendConsumptionPerDay(): void
    {
        // Distance EauAquarium (mm) qui dérive à la hausse malgré les marées :
        // la distance augmente ~= 240 mm / jour => l'eau baisse de 24 cm/jour.
        $rows = [];
        $base = new \DateTimeImmutable('2026-05-25 00:00:00');
        $distancesMm = [200, 260, 230, 290, 260, 320, 290, 350, 320, 380, 350, 410];
        foreach ($distancesMm as $i => $mm) {
            $ts = $base->modify('+' . ($i * 2) . ' hours');
            $rows[] = [
                'reading_time' => $ts->format('Y-m-d H:i:s'),
                'EauAquarium' => $mm,
                'EauReserve' => 500 - $i,
                'EauPotager' => 200,
            ];
        }

        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('fetchBetween')->willReturn(array_reverse($rows));

        $service = new WaterBalanceService($repo, new TideCycleDetector());
        $balance = $service->computeBalance('2026-05-25 00:00:00', '2026-05-25 22:00:00');

        $this->assertArrayHasKey('aquarium_consumption_per_day', $balance);
        $this->assertArrayHasKey('aquarium_trend_slope_per_day', $balance);
        $this->assertNotNull($balance['aquarium_consumption_per_day']);
        // Pente positive (distance qui augmente => eau qui baisse => consommation).
        $this->assertGreaterThan(0, $balance['aquarium_trend_slope_per_day']);
        $this->assertSame(
            max(0.0, $balance['aquarium_trend_slope_per_day']),
            $balance['aquarium_consumption_per_day']
        );
        // Niveaux convertis mm -> cm : baisse de l'ordre de ~20 cm/jour.
        $this->assertGreaterThan(10.0, $balance['aquarium_consumption_per_day']);
        $this->assertLessThan(30.0, $balance['aquarium_consumption_per_day']);
    }
}
