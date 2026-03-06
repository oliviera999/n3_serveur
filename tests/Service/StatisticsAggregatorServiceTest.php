<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\StatisticsAggregatorService;
use App\Service\SensorStatisticsService;
use PHPUnit\Framework\TestCase;

class StatisticsAggregatorServiceTest extends TestCase
{
    private StatisticsAggregatorService $service;
    private SensorStatisticsService $statsService;

    protected function setUp(): void
    {
        // Mock du SensorStatisticsService
        $this->statsService = $this->createMock(SensorStatisticsService::class);
        $this->service = new StatisticsAggregatorService($this->statsService);
    }

    public function testAggregateForSensor(): void
    {
        // Configurer les mocks pour retourner des valeurs
        $this->statsService->expects($this->once())
            ->method('min')
            ->willReturn(10.0);
        
        $this->statsService->expects($this->once())
            ->method('max')
            ->willReturn(30.0);
        
        $this->statsService->expects($this->once())
            ->method('avg')
            ->willReturn(20.0);
        
        $this->statsService->expects($this->once())
            ->method('stddev')
            ->willReturn(5.0);

        $result = $this->service->aggregateForSensor('TempAir', '2025-10-10 00:00:00', '2025-10-10 23:59:59');

        $this->assertEquals([
            'min' => 10.0,
            'max' => 30.0,
            'avg' => 20.0,
            'stddev' => 5.0,
        ], $result);
    }

    public function testFlattenForLegacy(): void
    {
        $stats = [
            'TempAir' => ['min' => 10.0, 'max' => 30.0, 'avg' => 20.0, 'stddev' => 5.0],
            'TempEau' => ['min' => 15.0, 'max' => 25.0, 'avg' => 20.0, 'stddev' => 3.0],
            'Humidite' => ['min' => 40.0, 'max' => 80.0, 'avg' => 60.0, 'stddev' => 10.0],
        ];

        $result = $this->service->flattenForLegacy($stats);

        // Vérifier TempAir
        $this->assertEquals(10.0, $result['min_tempair']);
        $this->assertEquals(30.0, $result['max_tempair']);
        $this->assertEquals(20.0, $result['avg_tempair']);
        $this->assertEquals(5.0, $result['stddev_tempair']);

        // Vérifier TempEau
        $this->assertEquals(15.0, $result['min_tempeau']);
        $this->assertEquals(25.0, $result['max_tempeau']);
        $this->assertEquals(20.0, $result['avg_tempeau']);
        $this->assertEquals(3.0, $result['stddev_tempeau']);

        // Vérifier Humidite
        $this->assertEquals(40.0, $result['min_humi']);
        $this->assertEquals(80.0, $result['max_humi']);
        $this->assertEquals(60.0, $result['avg_humi']);
        $this->assertEquals(10.0, $result['stddev_humi']);
    }

    public function testFlattenForLegacyWithPartialData(): void
    {
        $stats = [
            'TempAir' => ['min' => 10.0, 'max' => 30.0, 'avg' => 20.0, 'stddev' => 5.0],
            // TempEau manquant intentionnellement
        ];

        $result = $this->service->flattenForLegacy($stats);

        // TempAir doit être présent
        $this->assertArrayHasKey('min_tempair', $result);
        
        // TempEau ne doit pas être présent
        $this->assertArrayNotHasKey('min_tempeau', $result);
    }
}

