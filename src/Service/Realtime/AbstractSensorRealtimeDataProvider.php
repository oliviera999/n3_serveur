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
    /** GPIO stockant FreqWakeUp (temps de veille en secondes) pour MSP et N3PP. */
    private const FREQ_WAKEUP_GPIO = 107;
    /** Seuil par défaut si FreqWakeUp absent ou invalide en BDD. */
    private const DEFAULT_ONLINE_THRESHOLD_SECONDS = 600;
    private const ESTIMATED_LATENCY_SECONDS = 3.5;
    private const DEFAULT_UPTIME_DAYS = 30;

    public function __construct(
        protected AbstractSensorRepository $sensorRepo,
        private readonly int $board,
        private readonly int $expectedReadingIntervalMinutes = 2,
    ) {
    }

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
                'last_reading_ts' => null,
                'last_reading_ago_seconds' => null,
                'uptime_percentage' => 0.0,
                'readings_today' => 0,
                'average_latency_seconds' => null,
                'device_ip' => null,
                'module_uptime_seconds' => null,
            ];
        }

        $lastTs = strtotime($lastReadingDate);
        $secondsAgo = time() - $lastTs;
        $thresholdSeconds = $this->resolveOnlineThresholdSeconds();
        $isOnline = $secondsAgo < $thresholdSeconds;

        return [
            'online' => $isOnline,
            'last_reading' => $lastReadingDate,
            'last_reading_ts' => $lastTs !== false ? $lastTs : null,
            'last_reading_ago_seconds' => $secondsAgo,
            'uptime_percentage' => $this->calculateUptime(self::DEFAULT_UPTIME_DAYS),
            'readings_today' => $this->sensorRepo->countReadingsToday(),
            'average_latency_seconds' => $isOnline ? self::ESTIMATED_LATENCY_SECONDS : null,
            'device_ip' => null,
            'module_uptime_seconds' => $this->computeModuleUptimeSeconds(),
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

    protected function rowToSensors(array $row): array
    {
        $sensors = [];
        foreach ($this->sensorRepo->getSensorColumns() as $col) {
            $sensors[$col] = $row[$col] ?? null;
        }
        return $sensors;
    }

    /**
     * Seuil (secondes) sans nouvelle mesure au-delà duquel le module est considéré hors ligne.
     * Utilise FreqWakeUp (GPIO 107) en BDD si configuré, sinon 600 s par défaut.
     * Bornes : min 60 s, max 86400 s (24 h).
     */
    private function resolveOnlineThresholdSeconds(): int
    {
        $outputs = $this->getOutputsForBoard();
        foreach ($outputs as $o) {
            if ((int) ($o['gpio'] ?? 0) === self::FREQ_WAKEUP_GPIO) {
                $state = $o['state'] ?? null;
                if ($state === null || $state === '') {
                    return self::DEFAULT_ONLINE_THRESHOLD_SECONDS;
                }
                $seconds = (int) $state;
                if ($seconds <= 0) {
                    return self::DEFAULT_ONLINE_THRESHOLD_SECONDS;
                }
                return max(60, min(86400, $seconds));
            }
        }
        return self::DEFAULT_ONLINE_THRESHOLD_SECONDS;
    }

    private function calculateUptime(int $days): float
    {
        $start = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $end = date('Y-m-d H:i:s');
        $expected = ($days * 24 * 60) / $this->expectedReadingIntervalMinutes;
        if ($expected == 0) {
            return 0.0;
        }
        $actual = $this->sensorRepo->countReadingsBetween($start, $end);
        return min($actual / $expected * 100, 100.0);
    }

    /**
     * Calcule le temps total (en secondes) depuis la première mesure enregistrée.
     */
    private function computeModuleUptimeSeconds(): ?int
    {
        $firstDate = $this->sensorRepo->getFirstReadingDate();
        if ($firstDate === null) {
            return null;
        }
        return (int) (time() - strtotime($firstDate));
    }
}
