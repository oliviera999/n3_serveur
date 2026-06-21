<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Config\PglConfig;
use App\Repository\PglRepository;

/**
 * Fournisseur de données temps réel pour le module Poissonglouton (événements de recyclage).
 */
class PglRealtimeDataProvider implements RealtimeDataProviderInterface
{
    private const UPTIME_DAYS = 30;
    private const BUCKET_SECONDS = 3600;

    public function __construct(
        private PglRepository $repository,
    ) {
    }

    public function getLatestReadings(): array
    {
        $row = $this->repository->getLatestEvent();
        if ($row === null) {
            return ['timestamp' => time(), 'reading_time' => null, 'sensors' => []];
        }

        $latestTs = strtotime((string) $row['event_time']);
        if ($latestTs === false) {
            $latestTs = time();
        }

        $bucketStartTs = $this->floorToHour($latestTs);
        $bucketStartDatetime = date('Y-m-d H:i:s', $bucketStartTs);
        $bucketEndDatetime = date('Y-m-d H:i:s', $bucketStartTs + (self::BUCKET_SECONDS - 1));

        $events = $this->repository->getEventsBetween($bucketStartDatetime, $bucketEndDatetime);
        if ($events === []) {
            // Fallback : au moins refléter le dernier événement.
            $events = [$row];
        }

        return $this->buildBucketReading($bucketStartTs, $bucketStartDatetime, $events);
    }

    public function getReadingsSince(int $sinceTimestamp): array
    {
        $sinceDate = date('Y-m-d H:i:s', $sinceTimestamp);
        $rows = $this->repository->getEventsSince($sinceDate);

        if ($rows === []) {
            return [];
        }

        // Regroupe les événements par bucket horaire (pour alimenter des séries “par heure”).
        $buckets = [];
        foreach ($rows as $row) {
            $eventTime = (string) ($row['event_time'] ?? '');
            $ts = strtotime($eventTime);
            if ($ts === false) {
                continue;
            }

            $bucketStartTs = $this->floorToHour($ts);
            $buckets[$bucketStartTs][] = $row;
        }

        if ($buckets === []) {
            return [];
        }

        ksort($buckets);

        $result = [];
        foreach ($buckets as $bucketStartTs => $events) {
            $bucketStartDatetime = date('Y-m-d H:i:s', (int) $bucketStartTs);
            $result[] = $this->buildBucketReading((int) $bucketStartTs, $bucketStartDatetime, $events);
        }

        return $result;
    }

    public function getSystemHealth(): array
    {
        $base = $this->repository->getSystemHealth(PglConfig::ONLINE_THRESHOLD_SECONDS);

        $lastReading = $base['last_reading'] ?? null;
        $secondsAgo = $base['last_reading_ago_seconds'] ?? null;
        $isOnline = (bool) ($base['online'] ?? false);

        return [
            'online' => $isOnline,
            'last_reading' => $lastReading,
            'last_reading_ago_seconds' => $secondsAgo,
            'uptime_percentage' => $this->calculateUptime(),
            'readings_today' => $this->repository->countEventsToday(),
            'average_latency_seconds' => $isOnline ? 3.5 : null,
            'device_ip' => null,
            'module_uptime_seconds' => $this->computeModuleUptimeSeconds(),
        ];
    }

    public function getOutputsState(): array
    {
        return [];
    }

    public function getActiveAlerts(): array
    {
        return [];
    }

    /**
     * @param int $bucketStartTs
     * @param string $bucketStartDatetime
     * @param array<int, array<string, mixed>> $events
     * @return array{timestamp: int, reading_time: string|null, sensors: array<string, mixed>}
     */
    private function buildBucketReading(int $bucketStartTs, string $bucketStartDatetime, array $events): array
    {
        $countDelta = 0;
        $lastEvent = null;
        foreach ($events as $event) {
            $countDelta += (int) ($event['count_delta'] ?? 0);
            $lastEvent = $event;
        }

        $runningBefore = $this->repository->getRunningTotalBefore($bucketStartDatetime);

        $sensors = [
            'count_delta' => $countDelta,
            'total_count' => $runningBefore + $countDelta,
        ];

        $readingTime = null;
        if ($lastEvent !== null) {
            $readingTime = isset($lastEvent['event_time']) ? (string) $lastEvent['event_time'] : null;

            if (isset($lastEvent['battery_v'])) {
                $sensors['battery_v'] = (float) $lastEvent['battery_v'];
            }
            if (isset($lastEvent['rssi'])) {
                $sensors['rssi'] = (int) $lastEvent['rssi'];
            }
        }

        return [
            'timestamp' => $bucketStartTs,
            'reading_time' => $readingTime,
            'sensors' => $sensors,
        ];
    }

    private function floorToHour(int $unixTs): int
    {
        return $unixTs - ($unixTs % self::BUCKET_SECONDS);
    }

    private function calculateUptime(): float
    {
        $firstDate = $this->repository->getFirstEventDate();
        if ($firstDate === null) {
            return 0.0;
        }

        $start = date('Y-m-d H:i:s', strtotime('-' . self::UPTIME_DAYS . ' days'));
        $end = date('Y-m-d H:i:s');
        $eventCount = $this->repository->countEventsBetween($start, $end);

        // Estimation : 1 événement attendu par heure en fonctionnement normal
        $expected = self::UPTIME_DAYS * 24;

        return min($eventCount / $expected * 100, 100.0);
    }

    private function computeModuleUptimeSeconds(): ?int
    {
        $firstDate = $this->repository->getFirstEventDate();
        if ($firstDate === null) {
            return null;
        }

        $firstTs = strtotime($firstDate);
        if ($firstTs === false) {
            return null;
        }

        return time() - $firstTs;
    }
}
