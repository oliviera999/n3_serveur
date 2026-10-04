<?php

declare(strict_types=1);

namespace Tests\Service\Realtime;

use App\Repository\EnergieSensorRepository;
use App\Service\Realtime\EnergieRealtimeDataProvider;
use PHPUnit\Framework\TestCase;

final class EnergieRealtimeDataProviderTest extends TestCase
{
    private const COLUMNS = ['PanneauP', 'BatterieI', 'BatterieSoc', 'InaStatus'];

    private function repo(): EnergieSensorRepository
    {
        $repo = $this->createMock(EnergieSensorRepository::class);
        $repo->method('getSensorColumns')->willReturn(self::COLUMNS);

        return $repo;
    }

    public function testLatestReadingsWithoutDataReturnsEmptySensors(): void
    {
        $repo = $this->repo();
        $repo->method('getLatest')->willReturn(null);

        $latest = (new EnergieRealtimeDataProvider($repo))->getLatestReadings();

        $this->assertNull($latest['reading_time']);
        $this->assertSame([], $latest['sensors']);
        $this->assertIsInt($latest['timestamp']);
    }

    public function testLatestReadingsExposeEverySensorColumn(): void
    {
        $repo = $this->repo();
        $repo->method('getLatest')->willReturn([
            'id' => 9,
            'sensor' => 'energie',
            'PanneauP' => '21.5',
            'BatterieI' => '-0.42',
            'BatterieSoc' => null,
            'InaStatus' => '7',
            'reading_time' => '2026-10-03 12:00:10',
        ]);

        $latest = (new EnergieRealtimeDataProvider($repo))->getLatestReadings();

        $this->assertSame(strtotime('2026-10-03 12:00:10'), $latest['timestamp']);
        $this->assertSame('2026-10-03 12:00:10', $latest['reading_time']);
        $this->assertSame(
            ['PanneauP' => '21.5', 'BatterieI' => '-0.42', 'BatterieSoc' => null, 'InaStatus' => '7'],
            $latest['sensors'],
            'seules les colonnes capteurs sont exposées (pas id/sensor)'
        );
    }

    public function testReadingsSinceKeepsChronologicalOrder(): void
    {
        $since = strtotime('2026-10-03 12:00:00');
        $repo = $this->repo();
        $repo->expects($this->once())
            ->method('fetchBetween')
            ->with('2026-10-03 12:00:00', $this->isType('string'))
            ->willReturn([
                ['reading_time' => '2026-10-03 12:00:00', 'PanneauP' => '10'],
                ['reading_time' => '2026-10-03 12:00:10', 'PanneauP' => '11'],
            ]);

        $readings = (new EnergieRealtimeDataProvider($repo))->getReadingsSince($since);

        $this->assertCount(2, $readings);
        $this->assertSame($since, $readings[0]['timestamp']);
        $this->assertSame($since + 10, $readings[1]['timestamp']);
        $this->assertSame('11', $readings[1]['sensors']['PanneauP']);
        $this->assertNull($readings[1]['sensors']['BatterieI']);
    }

    public function testHealthWithoutDataIsOffline(): void
    {
        $repo = $this->repo();
        $repo->method('getLastReadingDate')->willReturn(null);

        $health = (new EnergieRealtimeDataProvider($repo))->getSystemHealth();

        $this->assertFalse($health['online']);
        $this->assertSame(0.0, $health['uptime_percentage']);
        $this->assertSame(0, $health['readings_today']);
        $this->assertNull($health['module_uptime_seconds']);
    }

    public function testHealthOnlineWhenLastReadingIsRecentAndUptimeOverOneDay(): void
    {
        $repo = $this->repo();
        $repo->method('getLastReadingDate')->willReturn(date('Y-m-d H:i:s', time() - 20));
        $repo->method('getFirstReadingDate')->willReturn(date('Y-m-d H:i:s', time() - 3600));
        $repo->method('countReadingsToday')->willReturn(360);
        // 4320 lectures reçues sur 8640 attendues (86400 s / 10 s) → 50 %.
        $repo->expects($this->once())
            ->method('countReadingsBetween')
            ->willReturnCallback(function (string $start, string $end): int {
                $span = strtotime($end) - strtotime($start);
                $this->assertEqualsWithDelta(86400, $span, 2, 'fenêtre d\'uptime = 1 jour');

                return 4320;
            });

        $health = (new EnergieRealtimeDataProvider($repo))->getSystemHealth();

        $this->assertTrue($health['online']);
        $this->assertGreaterThanOrEqual(20, $health['last_reading_ago_seconds']);
        $this->assertEqualsWithDelta(50.0, $health['uptime_percentage'], 1e-9);
        $this->assertSame(360, $health['readings_today']);
        $this->assertSame(3.5, $health['average_latency_seconds']);
        $this->assertNull($health['device_ip']);
        $this->assertGreaterThanOrEqual(3600, $health['module_uptime_seconds']);
    }

    public function testHealthOfflineAfterNinetySecondsAndUptimeCapped(): void
    {
        $repo = $this->repo();
        $repo->method('getLastReadingDate')->willReturn(date('Y-m-d H:i:s', time() - 91));
        $repo->method('getFirstReadingDate')->willReturn(date('Y-m-d H:i:s', time() - 86400 * 3));
        $repo->method('countReadingsToday')->willReturn(1);
        $repo->method('countReadingsBetween')->willReturn(9000);

        $health = (new EnergieRealtimeDataProvider($repo))->getSystemHealth();

        $this->assertFalse($health['online']);
        $this->assertNull($health['average_latency_seconds']);
        $this->assertSame(100.0, $health['uptime_percentage']);
    }

    public function testMeasurementOnlyFamilyHasNoOutputsNorAlerts(): void
    {
        $provider = new EnergieRealtimeDataProvider($this->repo());

        $this->assertSame([], $provider->getOutputsState());
        $this->assertSame([], $provider->getActiveAlerts());
    }
}
