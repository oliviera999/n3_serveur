<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Config\PglConfig;
use App\Repository\PglRepository;
use App\Service\Realtime\PglRealtimeDataProvider;
use PHPUnit\Framework\TestCase;

final class PglRealtimeDataProviderTest extends TestCase
{
    public function testGetLatestReadingsAggregatesBucketTotals(): void
    {
        $repo = $this->createMock(PglRepository::class);

        $latestEvent = [
            'event_time' => '2026-01-01 12:34:56',
            'count_delta' => 1,
            'battery_v' => '4.10',
            'rssi' => -60,
        ];

        $repo->method('getLatestEvent')->willReturn($latestEvent);

        // Provider doit requêter la fenêtre “heure” contenant le dernier event.
        $repo->method('getEventsBetween')->willReturn([
            [
                'event_time' => '2026-01-01 12:10:00',
                'count_delta' => 2,
                'battery_v' => '4.00',
                'rssi' => -65,
            ],
            [
                'event_time' => '2026-01-01 12:34:56',
                'count_delta' => 3,
                'battery_v' => '4.10',
                'rssi' => -60,
            ],
        ]);

        $repo->method('getRunningTotalBefore')->willReturn(10);

        $provider = new PglRealtimeDataProvider($repo);
        $latest = $provider->getLatestReadings();

        $this->assertIsArray($latest);
        $this->assertSame(strtotime('2026-01-01 12:00:00'), $latest['timestamp']);
        $this->assertSame('2026-01-01 12:34:56', $latest['reading_time']);
        $this->assertSame(5, $latest['sensors']['count_delta']); // 2 + 3
        $this->assertSame(15, $latest['sensors']['total_count']); // 10 + 5
        $this->assertSame(4.10, $latest['sensors']['battery_v']);
        $this->assertSame(-60, $latest['sensors']['rssi']);
    }

    public function testGetReadingsSinceGroupsByHourlyBucket(): void
    {
        $repo = $this->createMock(PglRepository::class);

        $sinceTimestamp = strtotime('2026-01-01 10:00:00');
        $repo->method('getEventsSince')->with('2026-01-01 10:00:00')->willReturn([
            // Bucket 10:00
            [
                'event_time' => '2026-01-01 10:05:00',
                'count_delta' => 2,
                'battery_v' => '4.00',
                'rssi' => -70,
            ],
            [
                'event_time' => '2026-01-01 10:59:00',
                'count_delta' => 1,
                'battery_v' => '4.01',
                'rssi' => -71,
            ],
            // Bucket 11:00
            [
                'event_time' => '2026-01-01 11:00:10',
                'count_delta' => 4,
                'battery_v' => '4.20',
                'rssi' => -60,
            ],
        ]);

        $repo->method('getRunningTotalBefore')->willReturnCallback(static function (string $dt): int {
            return match ($dt) {
                '2026-01-01 10:00:00' => 10,
                '2026-01-01 11:00:00' => 14,
                default => 0,
            };
        });

        $provider = new PglRealtimeDataProvider($repo);
        $readings = $provider->getReadingsSince($sinceTimestamp);

        $this->assertCount(2, $readings);

        $first = $readings[0];
        $this->assertSame(strtotime('2026-01-01 10:00:00'), $first['timestamp']);
        $this->assertSame(3, $first['sensors']['count_delta']); // 2 + 1
        $this->assertSame(13, $first['sensors']['total_count']); // 10 + 3
        $this->assertSame('2026-01-01 10:59:00', $first['reading_time']);
        $this->assertSame(4.01, $first['sensors']['battery_v']); // last event in bucket
        $this->assertSame(-71, $first['sensors']['rssi']);

        $second = $readings[1];
        $this->assertSame(strtotime('2026-01-01 11:00:00'), $second['timestamp']);
        $this->assertSame(4, $second['sensors']['count_delta']);
        $this->assertSame(18, $second['sensors']['total_count']); // 14 + 4
        $this->assertSame('2026-01-01 11:00:10', $second['reading_time']);
        $this->assertSame(4.20, $second['sensors']['battery_v']);
        $this->assertSame(-60, $second['sensors']['rssi']);
    }

    public function testGetSystemHealthIsComposedWithUptimeAndReadingsToday(): void
    {
        $repo = $this->createMock(PglRepository::class);

        $repo->method('getSystemHealth')
            ->with(PglConfig::ONLINE_THRESHOLD_SECONDS)
            ->willReturn([
                'online' => true,
                'last_reading' => '2026-01-01 12:00:00',
                'last_reading_ago_seconds' => 120,
                'source' => 'event',
            ]);

        $repo->method('getFirstEventDate')->willReturn('2025-12-01 00:00:00');
        $repo->method('countEventsBetween')->willReturn(30 * 24); // attendu => 100%
        $repo->method('countEventsToday')->willReturn(7);

        $provider = new PglRealtimeDataProvider($repo);
        $health = $provider->getSystemHealth();

        $this->assertTrue($health['online']);
        $this->assertSame('2026-01-01 12:00:00', $health['last_reading']);
        $this->assertSame(120, $health['last_reading_ago_seconds']);
        $this->assertSame(100.0, $health['uptime_percentage']);
        $this->assertSame(7, $health['readings_today']);
        $this->assertSame(3.5, $health['average_latency_seconds']);
        $this->assertIsInt($health['module_uptime_seconds']);
        $this->assertGreaterThan(0, $health['module_uptime_seconds']);
    }
}
