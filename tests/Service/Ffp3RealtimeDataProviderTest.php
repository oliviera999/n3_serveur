<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\Realtime\Ffp3RealtimeDataProvider;
use PHPUnit\Framework\TestCase;

class Ffp3RealtimeDataProviderTest extends TestCase
{
    public function testLatestReadingsExposeNullEauAquarium(): void
    {
        $sensorRepo = $this->createMock(SensorReadRepository::class);
        $sensorRepo->method('getLastReadings')->willReturn([
            'EauAquarium' => null,
            'EauReserve' => 150.0,
            'EauPotager' => 120.0,
            'TempEau' => 22.0,
            'TempAir' => 25.0,
            'Humidite' => 60.0,
            'Luminosite' => 300.0,
            'reading_time' => '2025-10-10 12:00:00',
        ]);

        $provider = new Ffp3RealtimeDataProvider(
            $sensorRepo,
            $this->createMock(OutputRepository::class)
        );

        $latest = $provider->getLatestReadings();

        $this->assertNull($latest['sensors']['EauAquarium']);
        $this->assertSame(15.0, $latest['sensors']['EauReserve']);
    }
}
