<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ChartDataService;
use PHPUnit\Framework\TestCase;

class ChartDataServiceTest extends TestCase
{
    private ChartDataService $service;

    protected function setUp(): void
    {
        $this->service = new ChartDataService();
    }

    public function testPrepareSeriesData(): void
    {
        $readings = [
            ['EauAquarium' => 10, 'EauReserve' => 20, 'TempAir' => 25, 'reading_time' => '2025-10-10 12:00:00'],
            ['EauAquarium' => 11, 'EauReserve' => 21, 'TempAir' => 26, 'reading_time' => '2025-10-10 11:00:00'],
        ];

        $result = $this->service->prepareSeriesData($readings);

        // Vérifier que les clés attendues existent
        $this->assertArrayHasKey('EauAquarium', $result);
        $this->assertArrayHasKey('EauReserve', $result);
        $this->assertArrayHasKey('TempAir', $result);

        // Vérifier que les valeurs sont JSON encodées
        $this->assertJson($result['EauAquarium']);
        
        // Vérifier l'ordre inversé (chronologique)
        $eauAquariumDecoded = json_decode($result['EauAquarium'], true);
        $this->assertEquals([11, 10], $eauAquariumDecoded);
    }

    public function testPrepareTimestamps(): void
    {
        $readings = [
            ['reading_time' => '2025-10-10 12:00:00'],
            ['reading_time' => '2025-10-10 11:00:00'],
        ];

        $result = $this->service->prepareTimestamps($readings);

        // Vérifier que c'est du JSON
        $this->assertJson($result);
        
        // Décoder et vérifier que ce sont des timestamps en millisecondes
        $timestamps = json_decode($result, true);
        $this->assertIsArray($timestamps);
        $this->assertCount(2, $timestamps);
        
        // Les timestamps doivent être > 0 et en millisecondes (13 chiffres)
        foreach ($timestamps as $ts) {
            $this->assertGreaterThan(0, $ts);
            $this->assertGreaterThan(1000000000000, $ts); // > 2001 en ms
        }
    }

    public function testExtractLastReadings(): void
    {
        $lastReading = [
            'TempAir' => 25.5,
            'TempEau' => 18.2,
            'Humidite' => 65,
            'Luminosite' => 500,
            'EauAquarium' => 10,
            'EauReserve' => 20,
            'EauPotager' => 15,
            'reading_time' => '2025-10-10 12:00:00',
        ];

        $result = $this->service->extractLastReadings($lastReading);

        $this->assertEquals(25.5, $result['tempair']);
        $this->assertEquals(18.2, $result['tempeau']);
        $this->assertEquals(65, $result['humi']);
        $this->assertEquals(500, $result['lumi']);
        $this->assertEquals(10, $result['eauaqua']);
        $this->assertEquals(20, $result['eaureserve']);
        $this->assertEquals(15, $result['eaupota']);
        $this->assertEquals('2025-10-10 12:00:00', $result['time']);
    }

    public function testExtractLastReadingsWithNull(): void
    {
        $result = $this->service->extractLastReadings(null);

        // Vérifier les valeurs par défaut
        $this->assertEquals(0, $result['tempair']);
        $this->assertEquals(0, $result['tempeau']);
        $this->assertEquals(0, $result['humi']);
        $this->assertEquals(0, $result['lumi']);
        $this->assertEquals(0, $result['eauaqua']);
        $this->assertEquals(0, $result['eaureserve']);
        $this->assertEquals(0, $result['eaupota']);
        $this->assertNotEmpty($result['time']);
    }

    public function testExtractLastReadingsWithEmptyArray(): void
    {
        $result = $this->service->extractLastReadings([]);

        // Vérifier les valeurs par défaut
        $this->assertEquals(0, $result['tempair']);
        $this->assertEquals(0, $result['tempeau']);
    }
}

