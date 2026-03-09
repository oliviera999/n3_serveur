<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Repository\AbstractSensorRepository;

/**
 * Classe abstraite pour les fournisseurs temps réel MSP1 et N3PP.
 * Factorise la logique commune : latest readings, readings since, system health,
 * outputs state, uptime. Chaque sous-classe fournit le board, les repos typés
 * et la liste des colonnes capteurs.
 */
abstract class AbstractSensorRealtimeDataProvider implements RealtimeDataProviderInterface
{
    private const ONLINE_THRESHOLD_SECONDS = 600;
    private const ESTIMATED_LATENCY_SECONDS = 3.5;
    private const DEFAULT_UPTIME_DAYS = 30;

    public function __construct(
        protected AbstractSensorRepository $sensorRepo,
        private readonly int $board,
        private readonly int $expectedReadingIntervalMinutes = 2,
    ) {}

    abstract protected function getOutputsForBoard(): array;

    public function getLatestReadings(): array
    {
        $row = $this->sensorRepo->getLatest();
        if ($row === null) {
            return ['timestamp' => time(), 'reading_time' => null, 'sensors' => []];
        }

        return [
            'timestamp' => strtotime($row['reading_time']),
            'reading_time' => $row['reading_time'],
            'sensors' => $this->rowToSensors($row),
        ];
    }

    public function getReadingsSince(int $sinceTimestamp): array
    {
        $sinceDate = date('Y-m-d H:i:s', $sinceTimestamp);
        $rows = $this->sensorRepo->fetchBetween($sinceDate, date('Y-m-d H:i:s'));

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'timestamp' => strtotime($row['reading_time']),
                'reading_time' => $row['reading_time'],
                'sensors' => $this->rowToSensors($row),
            ];
        }
        return $result;
    }

    public function getSystemHealth(): array
    {
        $lastReadingDate = $this->sensorRepo->getLastReadingDate();
        if ($lastReadingDate === null) {
            return [
                'online' => false,
                'last_reading' => null,
                'last_reading_ago_seconds' => null,
                'uptime_percentage' => 0.0,
                'readings_today' => 0,
                'average_latency_seconds' => null,
            ];
        }

        $lastTs = strtotime($lastReadingDate);
        $secondsAgo = time() - $lastTs;
        $isOnline = $secondsAgo < self::ONLINE_THRESHOLD_SECONDS;

        return [
            'online' => $isOnline,
            'last_reading' => $lastReadingDate,
            'last_reading_ago_seconds' => $secondsAgo,
            'uptime_percentage' => $this->calculateUptime(self::DEFAULT_UPTIME_DAYS),
            'readings_today' => $this->sensorRepo->countReadingsToday(),
            'average_latency_seconds' => $isOnline ? self::ESTIMATED_LATENCY_SECONDS : null,
        ];
    }

    public function getOutputsState(): array
    {
        $outputs = $this->getOutputsForBoard();
        $result = [];
        foreach ($outputs as $o) {
            $result[] = [
                'id' => isset($o['id']) ? (int) $o['id'] : null,
                'gpio' => (int) ($o['gpio'] ?? 0),
                'name' => $o['name'] ?? '',
                'state' => $o['state'] ?? '0',
                'board' => $this->board,
            ];
        }
        return $result;
    }

    public function getActiveAlerts(): array
    {
        return [];
    }

    /**
     * Convertit une ligne BDD en tableau de capteurs.
     * Implémentation par défaut : extrait les colonnes déclarées par getSensorColumns().
     * Peut être surchargée si un mapping spécifique est nécessaire.
     */
    protected function rowToSensors(array $row): array
    {
        $sensors = [];
        foreach ($this->sensorRepo->getSensorColumns() as $col) {
            $sensors[$col] = $row[$col] ?? null;
        }
        return $sensors;
    }

    private function calculateUptime(int $days): float
    {
        $start = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $end = date('Y-m-d H:i:s');
        $expected = ($days * 24 * 60) / $this->expectedReadingIntervalMinutes;
        if ($expected == 0) {
            return 0.0;
        }
        $rows = $this->sensorRepo->fetchBetween($start, $end);
        return min(count($rows) / $expected * 100, 100.0);
    }
}
