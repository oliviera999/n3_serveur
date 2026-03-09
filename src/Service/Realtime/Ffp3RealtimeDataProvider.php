<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Config\TableConfig;
use App\Repository\SensorReadRepository;
use App\Repository\OutputRepository;
use PDO;

/**
 * Fournisseur de données temps réel FFP3 (aquaponie).
 * Conserve sa logique propre (boolean GPIO, device_ip, multi-boards findAll).
 */
class Ffp3RealtimeDataProvider implements RealtimeDataProviderInterface
{
    private const ONLINE_THRESHOLD_SECONDS = 600;
    private const EXPECTED_READING_INTERVAL_MINUTES = 3;
    private const ESTIMATED_LATENCY_SECONDS = 3.5;
    private const DEFAULT_UPTIME_DAYS = 30;
    private const BOOLEAN_GPIO_EXCEPTIONS = [101, 108, 109, 110, 115];

    /** @var string[] Colonnes capteurs FFP3 */
    private const SENSOR_COLUMNS = [
        'EauAquarium', 'EauReserve', 'EauPotager',
        'TempEau', 'TempAir', 'Humidite', 'Luminosite',
        'etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV',
        'bouffePetits', 'bouffeGros',
    ];

    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private OutputRepository $outputRepo,
        private PDO $pdo
    ) {
    }

    public function getLatestReadings(): array
    {
        $lastReadings = $this->sensorReadRepo->getLastReadings();

        if (!$lastReadings) {
            return [
                'timestamp' => time(),
                'reading_time' => null,
                'sensors' => [],
            ];
        }

        $readingTimestamp = strtotime($lastReadings['reading_time']);

        $sensors = [];
        foreach (self::SENSOR_COLUMNS as $col) {
            $sensors[$col] = $lastReadings[$col] ?? null;
        }

        return [
            'timestamp' => $readingTimestamp,
            'reading_time' => $lastReadings['reading_time'],
            'sensors' => $sensors,
        ];
    }

    public function getReadingsSince(int $sinceTimestamp): array
    {
        $sinceDate = date('Y-m-d H:i:s', $sinceTimestamp);

        $readings = $this->sensorReadRepo->getReadingsSince($sinceDate);

        $result = [];
        foreach ($readings as $reading) {
            $sensors = [];
            foreach (self::SENSOR_COLUMNS as $col) {
                $sensors[$col] = $reading[$col] ?? null;
            }
            $result[] = [
                'timestamp' => strtotime($reading['reading_time']),
                'reading_time' => $reading['reading_time'],
                'sensors' => $sensors,
            ];
        }

        return $result;
    }

    public function getSystemHealth(): array
    {
        $lastReadingDateStr = $this->sensorReadRepo->getLastReadingDate();

        if ($lastReadingDateStr === null) {
            return [
                'online' => false,
                'last_reading' => null,
                'last_reading_ago_seconds' => null,
                'uptime_percentage' => 0.0,
                'readings_today' => 0,
                'average_latency_seconds' => null,
                'device_ip' => $this->readDeviceIpFile(),
            ];
        }

        $lastReadingTimestamp = strtotime($lastReadingDateStr);
        $now = time();
        $secondsSinceLastReading = $now - $lastReadingTimestamp;

        $isOnline = $secondsSinceLastReading < self::ONLINE_THRESHOLD_SECONDS;
        $uptimePercentage = $this->calculateUptime(self::DEFAULT_UPTIME_DAYS);
        $readingsToday = $this->countReadingsToday();
        $averageLatency = $isOnline ? self::ESTIMATED_LATENCY_SECONDS : null;

        return [
            'online' => $isOnline,
            'last_reading' => $lastReadingDateStr,
            'last_reading_ago_seconds' => $secondsSinceLastReading,
            'uptime_percentage' => $uptimePercentage,
            'readings_today' => $readingsToday,
            'average_latency_seconds' => $averageLatency,
            'device_ip' => $this->readDeviceIpFile(),
        ];
    }

    private function readDeviceIpFile(): ?string
    {
        $projectRoot = dirname(__DIR__, 2);
        $env = TableConfig::getEnvironment();
        $path = $projectRoot . '/var/last_device_ip_' . $env . '.txt';
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $trimmed = trim($content);
        return $trimmed === '' ? null : $trimmed;
    }

    public function getOutputsState(): array
    {
        $outputs = $this->outputRepo->findAll();

        $booleanGpios = [];
        for ($i = 0; $i < 100; $i++) {
            $booleanGpios[$i] = true;
        }
        foreach (self::BOOLEAN_GPIO_EXCEPTIONS as $specialGpio) {
            $booleanGpios[$specialGpio] = true;
        }

        $result = [];
        foreach ($outputs as $output) {
            $gpio = (int)($output['gpio'] ?? 0);
            $state = $output['state'];

            if (isset($booleanGpios[$gpio])) {
                $state = (int)$state;
            }

            $result[] = [
                'id' => isset($output['id']) ? (int)$output['id'] : null,
                'gpio' => $gpio,
                'name' => $output['name'],
                'state' => $state,
                'board' => $output['board'] ?? null,
            ];
        }

        return $result;
    }

    public function getActiveAlerts(): array
    {
        return [];
    }

    private function calculateUptime(int $days): float
    {
        $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $endDate = date('Y-m-d H:i:s');

        $expectedReadings = ($days * 24 * 60) / self::EXPECTED_READING_INTERVAL_MINUTES;
        $actualReadings = $this->sensorReadRepo->countReadingsBetween($startDate, $endDate);

        if ($expectedReadings == 0) {
            return 0.0;
        }

        $uptime = ($actualReadings / $expectedReadings) * 100;

        return min($uptime, 100.0);
    }

    private function countReadingsToday(): int
    {
        $startOfDay = date('Y-m-d 00:00:00');
        $endOfDay = date('Y-m-d 23:59:59');

        return $this->sensorReadRepo->countReadingsBetween($startOfDay, $endOfDay);
    }
}
