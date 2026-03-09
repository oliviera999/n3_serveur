<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Repository\MspOutputRepository;
use App\Repository\MspSensorRepository;

/**
 * Fournisseur de données temps réel pour MSP1 (station météo).
 * Implémente le contrat commun pour realtime-updater.js / control-sync.js.
 */
class MspRealtimeDataProvider implements RealtimeDataProviderInterface
{
    private const ONLINE_THRESHOLD_SECONDS = 600;
    private const DEFAULT_BOARD = 2;

    public function __construct(
        private MspSensorRepository $sensorRepo,
        private MspOutputRepository $outputRepo
    ) {
    }

    public function getLatestReadings(): array
    {
        $row = $this->sensorRepo->getLatest();
        if ($row === null) {
            return [
                'timestamp' => time(),
                'reading_time' => null,
                'sensors' => [],
            ];
        }
        $readingTimestamp = strtotime($row['reading_time']);
        return [
            'timestamp' => $readingTimestamp,
            'reading_time' => $row['reading_time'],
            'sensors' => $this->rowToSensors($row),
        ];
    }

    /** @inheritDoc */
    public function getReadingsSince(int $sinceTimestamp): array
    {
        $sinceDate = date('Y-m-d H:i:s', $sinceTimestamp);
        $endDate = date('Y-m-d H:i:s');
        $rows = $this->sensorRepo->fetchBetween($sinceDate, $endDate);
        $readings = [];
        foreach ($rows as $row) {
            $readings[] = [
                'timestamp' => strtotime($row['reading_time']),
                'reading_time' => $row['reading_time'],
                'sensors' => $this->rowToSensors($row),
            ];
        }
        return $readings;
    }

    public function getSystemHealth(): array
    {
        $lastReadingDateStr = $this->sensorRepo->getLastReadingDate();
        if ($lastReadingDateStr === null) {
            return [
                'online' => false,
                'last_reading' => null,
                'last_reading_ago_seconds' => null,
                'uptime_percentage' => 0.0,
                'readings_today' => 0,
                'average_latency_seconds' => null,
                'device_ip' => null,
            ];
        }
        $lastTs = strtotime($lastReadingDateStr);
        $secondsSince = time() - $lastTs;
        $isOnline = $secondsSince < self::ONLINE_THRESHOLD_SECONDS;
        $readingsToday = $this->sensorRepo->countReadingsToday();
        return [
            'online' => $isOnline,
            'last_reading' => $lastReadingDateStr,
            'last_reading_ago_seconds' => $secondsSince,
            'uptime_percentage' => 100.0,
            'readings_today' => $readingsToday,
            'average_latency_seconds' => $isOnline ? 3.5 : null,
            'device_ip' => null,
        ];
    }

    public function getOutputsState(): array
    {
        $outputs = $this->outputRepo->getAllForBoard(self::DEFAULT_BOARD);
        $list = [];
        foreach ($outputs as $row) {
            $list[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'gpio' => (int) ($row['gpio'] ?? 0),
                'name' => $row['name'] ?? '',
                'state' => $row['state'] ?? '0',
                'board' => self::DEFAULT_BOARD,
            ];
        }
        return $list;
    }

    public function getActiveAlerts(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowToSensors(array $row): array
    {
        $sensors = [];
        foreach (MspSensorRepository::SENSOR_COLUMNS as $col) {
            $sensors[$col] = $row[$col] ?? null;
        }
        return $sensors;
    }
}
