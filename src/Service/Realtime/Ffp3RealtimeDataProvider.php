<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Config\TableConfig;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Util\StateNormalizer;

/**
 * Fournisseur de données temps réel pour FFP3 (aquaponie).
 * Implémente RealtimeDataProviderInterface pour l'API /api/realtime/*.
 */
class Ffp3RealtimeDataProvider implements RealtimeDataProviderInterface
{
    private const ONLINE_THRESHOLD_SECONDS = 600;
    private const ESTIMATED_LATENCY_SECONDS = 3.5;
    private const DEFAULT_UPTIME_DAYS = 30;
    private const EXPECTED_READING_INTERVAL_MINUTES = 3;

    /** GPIO considérés comme booléens pour normalisation */
    private const BOOLEAN_GPIO_EXCEPTIONS = [101, 108, 109, 110, 115];

    private const SENSOR_KEYS = [
        'EauAquarium', 'EauReserve', 'EauPotager', 'TempEau', 'TempAir', 'Humidite', 'Luminosite',
        'etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV', 'bouffePetits', 'bouffeGros',
    ];

    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private OutputRepository $outputRepo,
        \PDO $pdo, // Conservé pour compatibilité DI (dependencies.php), non utilisé
    ) {
    }

    public function getLatestReadings(): array
    {
        $lastReadings = $this->sensorReadRepo->getLastReadings(1);

        if ($lastReadings === [] || !isset($lastReadings['reading_time'])) {
            return [
                'timestamp' => time(),
                'reading_time' => null,
                'sensors' => [],
            ];
        }

        return [
            'timestamp' => strtotime($lastReadings['reading_time']),
            'reading_time' => $lastReadings['reading_time'],
            'sensors' => $this->rowToSensors($lastReadings),
        ];
    }

    public function getReadingsSince(int $sinceTimestamp): array
    {
        $sinceDate = date('Y-m-d H:i:s', $sinceTimestamp);
        $readings = $this->sensorReadRepo->getReadingsSince($sinceDate);

        $result = [];
        foreach ($readings as $reading) {
            $result[] = [
                'timestamp' => strtotime($reading['reading_time']),
                'reading_time' => $reading['reading_time'],
                'sensors' => $this->rowToSensors($reading),
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

        $lastTs = strtotime($lastReadingDateStr);
        $secondsAgo = time() - $lastTs;
        $isOnline = $secondsAgo < self::ONLINE_THRESHOLD_SECONDS;

        return [
            'online' => $isOnline,
            'last_reading' => $lastReadingDateStr,
            'last_reading_ago_seconds' => $secondsAgo,
            'uptime_percentage' => $this->calculateUptime(self::DEFAULT_UPTIME_DAYS),
            'readings_today' => $this->countReadingsToday(),
            'average_latency_seconds' => $isOnline ? self::ESTIMATED_LATENCY_SECONDS : null,
            'device_ip' => $this->readDeviceIpFile(),
        ];
    }

    public function getOutputsState(): array
    {
        $outputs = $this->outputRepo->findAll();

        $booleanGpios = array_fill(0, 100, true);
        foreach (self::BOOLEAN_GPIO_EXCEPTIONS as $gpio) {
            $booleanGpios[$gpio] = true;
        }

        $result = [];
        foreach ($outputs as $output) {
            $gpio = (int) ($output['gpio'] ?? 0);
            $state = $output['state'] ?? '0';

            if (isset($booleanGpios[$gpio])) {
                $state = (int) StateNormalizer::normalize($gpio, $state);
            }

            $result[] = [
                'id' => isset($output['id']) ? (int) $output['id'] : null,
                'gpio' => $gpio,
                'name' => $output['name'] ?? '',
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

    private function rowToSensors(array $row): array
    {
        $sensors = [];
        foreach (self::SENSOR_KEYS as $key) {
            $sensors[$key] = $row[$key] ?? null;
        }
        return $sensors;
    }

    private function calculateUptime(int $days): float
    {
        $start = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $end = date('Y-m-d H:i:s');
        $expected = ($days * 24 * 60) / self::EXPECTED_READING_INTERVAL_MINUTES;
        if ($expected == 0) {
            return 0.0;
        }
        $count = $this->sensorReadRepo->countReadingsBetween($start, $end);
        return min($count / $expected * 100, 100.0);
    }

    private function countReadingsToday(): int
    {
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 23:59:59');
        return $this->sensorReadRepo->countReadingsBetween($start, $end);
    }

    private function readDeviceIpFile(): ?string
    {
        $projectRoot = dirname(__DIR__, 3);
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
}
