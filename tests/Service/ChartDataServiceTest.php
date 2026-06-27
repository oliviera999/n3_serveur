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

    public function testExtractLastReadingsNullEauAquarium(): void
    {
        $lastReading = [
            'TempAir' => 25.5,
            'TempEau' => 18.2,
            'Humidite' => 65,
            'Luminosite' => 500,
            'EauAquarium' => null,
            'EauReserve' => 20,
            'EauPotager' => 15,
            'reading_time' => '2025-10-10 12:00:00',
        ];

        $result = $this->service->extractLastReadings($lastReading);

        $this->assertNull($result['eauaqua']);
        $this->assertSame(20.0, $result['eaureserve']);
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

    // --- Sous-échantillonnage des longues séries (downsampling) -----------

    /**
     * Construit $n lectures DESC (du plus récent au plus ancien) comme la DB.
     *
     * @return list<array<string, mixed>>
     */
    private function makeReadingsDesc(int $n): array
    {
        $rows = [];
        // Index 0 = le plus récent ; on recule d'une minute par ligne.
        $base = strtotime('2025-10-10 12:00:00');
        for ($i = 0; $i < $n; $i++) {
            $ts = $base - $i * 60;
            $rows[] = [
                'EauAquarium' => $i,
                'EauReserve' => 100 + $i,
                'TempAir' => 20 + ($i % 10),
                'reading_time' => date('Y-m-d H:i:s', $ts),
            ];
        }

        return $rows;
    }

    public function testPrepareSeriesDataNeverExceedsCap(): void
    {
        $readings = $this->makeReadingsDesc(5000);
        $result = $this->service->prepareSeriesData($readings, 2000);

        $decoded = json_decode($result['EauAquarium'], true);
        $this->assertLessThanOrEqual(2000, count($decoded));
        $this->assertCount(count($decoded), json_decode($result['EauReserve'], true));
    }

    public function testPrepareSeriesDataIdentityBelowCap(): void
    {
        $readings = $this->makeReadingsDesc(50);

        $downsampled = $this->service->prepareSeriesData($readings, 2000);
        $plain = $this->service->prepareSeriesData($readings, 999999);

        // En dessous du plafond : identité stricte (rétro-compatibilité totale).
        $this->assertSame($plain, $downsampled);

        // Et toutes les clés historiques restent présentes, ordre chronologique.
        $decoded = json_decode($downsampled['EauAquarium'], true);
        $this->assertSame(range(49, 0), $decoded); // DESC -> reversed => 49..0
        $this->assertCount(50, $decoded);
    }

    public function testPrepareSeriesDataPreservesFirstAndLast(): void
    {
        $readings = $this->makeReadingsDesc(5000);
        $result = $this->service->prepareSeriesData($readings, 1000);

        // Après array_reverse interne, la série va du plus ancien (valeur 4999)
        // au plus récent (valeur 0).
        $decoded = json_decode($result['EauAquarium'], true);
        $this->assertSame(4999, $decoded[0]);
        $this->assertSame(0, $decoded[count($decoded) - 1]);
    }

    public function testPrepareTimestampsAndSeriesStayCoherent(): void
    {
        $readings = $this->makeReadingsDesc(5000);
        $maxPoints = 1500;

        $series = $this->service->prepareSeriesData($readings, $maxPoints);
        $timestamps = json_decode($this->service->prepareTimestamps($readings, $maxPoints), true);
        $values = json_decode($series['EauAquarium'], true);

        // Même nombre de points entre timestamps et valeurs (sélection identique).
        $this->assertSameSize($timestamps, $values);
        $this->assertLessThanOrEqual($maxPoints, count($timestamps));

        // Ordre temporel strictement croissant (timestamps en ms, sortie ASC).
        for ($i = 1; $i < count($timestamps); $i++) {
            $this->assertGreaterThan($timestamps[$i - 1], $timestamps[$i]);
        }
    }

    public function testPrepareGenericSeriesCapAndCoherence(): void
    {
        // Le service attend des lectures en ordre chronologique (ASC), tel que
        // fetchBetween() les renvoie. makeReadingsDesc produit du DESC : on inverse
        // pour reproduire l'ASC fourni par le contrôleur.
        $readings = array_reverse($this->makeReadingsDesc(4900));
        $columns = ['EauAquarium', 'TempAir'];

        $series = $this->service->prepareGenericSeries($readings, $columns, 2000);

        $this->assertLessThanOrEqual(2000, count($series['reading_time']));
        $this->assertSameSize($series['reading_time'], $series['EauAquarium']);
        $this->assertSameSize($series['reading_time'], $series['TempAir']);

        // Premier et dernier points préservés. makeReadingsDesc(4900) a index 0
        // = valeur 0 (plus récent) ; array_reverse => index 0 = valeur 4899.
        $this->assertSame(4899.0, $series['EauAquarium'][0]);
        $this->assertSame(0.0, $series['EauAquarium'][count($series['EauAquarium']) - 1]);

        // Ordre temporel monotone croissant (ASC : du plus ancien au plus récent).
        $ts = $series['reading_time'];
        for ($i = 1; $i < count($ts); $i++) {
            $this->assertGreaterThan($ts[$i - 1], $ts[$i]);
        }
    }

    public function testPrepareGenericSeriesIdentityBelowCap(): void
    {
        $readings = array_reverse($this->makeReadingsDesc(100));
        $columns = ['EauAquarium', 'TempAir'];

        $a = $this->service->prepareGenericSeries($readings, $columns, 2000);
        $b = $this->service->prepareGenericSeries($readings, $columns, 999999);

        $this->assertSame($b, $a);
        $this->assertCount(100, $a['reading_time']);
    }
}
