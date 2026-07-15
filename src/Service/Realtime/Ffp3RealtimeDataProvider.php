<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Config\TableConfig;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Util\Ffp3WaterLevelUnit;
use App\Util\ReadingTimeParser;

/**
 * Fournisseur de données temps réel pour FFP3 (aquaponie).
 * Utilise SensorReadRepository et OutputRepository (tables ffp3Data, ffp3Outputs).
 */
class Ffp3RealtimeDataProvider implements RealtimeDataProviderInterface
{
    /** GPIO stockant FreqWakeUp (temps de veille en secondes) dans ffp3Outputs. */
    private const FREQ_WAKEUP_GPIO = 116;
    /** Seuil par défaut si FreqWakeUp absent ou invalide en BDD (15 min pour couvrir une veille typique). */
    private const DEFAULT_ONLINE_THRESHOLD_SECONDS = 900;
    /** Marge ajoutée au seuil pour éviter de passer hors ligne juste avant le prochain réveil (latence, horloge). */
    private const ONLINE_THRESHOLD_MARGIN_SECONDS = 60;
    private const EXPECTED_READING_INTERVAL_MINUTES = 3;
    private const ESTIMATED_LATENCY_SECONDS = 3.5;
    private const DEFAULT_UPTIME_DAYS = 30;
    /**
     * GPIO ≥ 100 qui, par EXCEPTION à la règle « ≥ 100 = paramètre (chaîne) », portent un état
     * BOOLÉEN (flag 0/1) et doivent être renvoyés en entier — cf. contrat outputs/state
     * (docs/API_REALTIME_OUTPUTS_CONTRAT.md §1.3). D'après {@see \App\Config\Ffp3GpioMap} :
     *   101 mailNotif · 108 bouffePetits · 109 bouffeGros · 110 resetMode · 115 WakeUp.
     * Les actionneurs physiques (GPIO < 100 : 2/15/16/18) sont déjà coercés en entier par
     * l'intervalle 0-99 ci-dessous ; ces cinq entrées l'ÉTENDENT aux flags ≥ 100 (pas un no-op).
     */
    private const BOOLEAN_GPIO_EXCEPTIONS = [101, 108, 109, 110, 115];

    private const FFP3_SENSOR_KEYS = [
        'EauAquarium', 'EauReserve', 'EauPotager', 'TempEau', 'TempAir', 'Humidite', 'Luminosite',
        'etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV', 'bouffePetits', 'bouffeGros',
    ];

    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private OutputRepository $outputRepo,
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

        return [
            'timestamp' => $this->readingTimeToUnixTimestamp((string) $lastReadings['reading_time']),
            'reading_time' => $lastReadings['reading_time'],
            'sensors' => $this->extractSensors($lastReadings),
        ];
    }

    public function getReadingsSince(int $sinceTimestamp): array
    {
        $sinceDate = date('Y-m-d H:i:s', $sinceTimestamp);
        $readings = $this->sensorReadRepo->getReadingsSince($sinceDate);

        $result = [];
        foreach ($readings as $reading) {
            $result[] = [
                'timestamp' => $this->readingTimeToUnixTimestamp((string) $reading['reading_time']),
                'reading_time' => $reading['reading_time'],
                'sensors' => $this->extractSensors($reading),
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
                'last_reading_ts' => null,
                'last_reading_ago_seconds' => null,
                'uptime_percentage' => 0.0,
                'readings_today' => 0,
                'average_latency_seconds' => null,
                'device_ip' => $this->readDeviceIpFile(),
                'module_uptime_seconds' => null,
            ];
        }

        $lastReadingTs = strtotime($lastReadingDateStr);
        $secondsSinceLastReading = time() - $lastReadingTs;
        // Seuil = temps de veille prévu en BDD (FreqWakeUp) + marge : le badge reste LIVE pendant toute la veille
        $thresholdSeconds = min(86400, $this->resolveOnlineThresholdSeconds() + self::ONLINE_THRESHOLD_MARGIN_SECONDS);
        $isOnline = $secondsSinceLastReading < $thresholdSeconds;

        $firstReadingDateStr = $this->sensorReadRepo->getFirstReadingDate();
        $moduleUptimeSeconds = $firstReadingDateStr !== null
            ? time() - strtotime($firstReadingDateStr)
            : null;

        return [
            'online' => $isOnline,
            'last_reading' => $lastReadingDateStr,
            'last_reading_ts' => $lastReadingTs !== false ? $lastReadingTs : null,
            'last_reading_ago_seconds' => $secondsSinceLastReading,
            'uptime_percentage' => $this->calculateUptime(self::DEFAULT_UPTIME_DAYS),
            'readings_today' => $this->countReadingsToday(),
            'average_latency_seconds' => $isOnline ? self::ESTIMATED_LATENCY_SECONDS : null,
            'device_ip' => $this->readDeviceIpFile(),
            'module_uptime_seconds' => $moduleUptimeSeconds,
        ];
    }

    public function getOutputsState(): array
    {
        $outputs = $this->outputRepo->findAll();

        // Ensemble des GPIO dont l'état est booléen (renvoyé en entier) :
        //  - 0-99  : actionneurs physiques (relais on/off) ;
        //  - + les EXCEPTIONS ≥ 100 (flags 0/1, cf. BOOLEAN_GPIO_EXCEPTIONS) qui s'AJOUTENT
        //    ici à l'ensemble (nouvelles clés ≥ 100 → pas un no-op).
        // Les autres GPIO ≥ 100 (mail, seuils, heures, angles…) restent des chaînes.
        $booleanGpios = array_fill(0, 100, true);
        foreach (self::BOOLEAN_GPIO_EXCEPTIONS as $g) {
            $booleanGpios[$g] = true;
        }

        $result = [];
        foreach ($outputs as $output) {
            $gpio = (int) ($output['gpio'] ?? 0);
            $state = $output['state'];
            if (isset($booleanGpios[$gpio])) {
                $state = (int) $state;
            }
            $result[] = [
                'id' => isset($output['id']) ? (int) $output['id'] : null,
                'gpio' => $gpio,
                'name' => $output['name'] ?? '',
                'state' => $state,
                'board' => isset($output['board']) ? (int) $output['board'] : null,
            ];
        }
        return $result;
    }

    public function getActiveAlerts(): array
    {
        return [];
    }

    private function extractSensors(array $row): array
    {
        $scaled = Ffp3WaterLevelUnit::scaleSensorRowFromMmToCm($row) ?? $row;
        $sensors = [];
        foreach (self::FFP3_SENSOR_KEYS as $key) {
            $sensors[$key] = $scaled[$key] ?? null;
        }
        return $sensors;
    }

    /**
     * Même interprétation que {@see \App\Service\ChartDataService::prepareTimestamps} :
     * la chaîne SQL `reading_time` est lue en Europe/Paris pour que l’epoch Unix
     * corresponde aux abscisses des graphiques aquaponie (évite le warning Highcharts #15).
     */
    private function readingTimeToUnixTimestamp(string $readingTime): int
    {
        return ReadingTimeParser::toUnixSeconds($readingTime) ?? time();
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

    private function calculateUptime(int $days): float
    {
        $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $endDate = date('Y-m-d H:i:s');
        $expectedReadings = ($days * 24 * 60) / self::EXPECTED_READING_INTERVAL_MINUTES;
        $actualReadings = $this->sensorReadRepo->countReadingsBetween($startDate, $endDate);
        if ($expectedReadings == 0) {
            return 0.0;
        }
        return min(($actualReadings / $expectedReadings) * 100, 100.0);
    }

    private function countReadingsToday(): int
    {
        return $this->sensorReadRepo->countReadingsBetween(
            date('Y-m-d 00:00:00'),
            date('Y-m-d 23:59:59')
        );
    }

    /**
     * Détermine le seuil en secondes pour considérer le module comme « live » (badge vert).
     * Utilise FreqWakeUp (GPIO 116, temps de veille) en BDD ; sinon 900 s par défaut.
     * Le badge reste LIVE quand le serveur reçoit des données ET durant tout le temps de veille
     * prévu en BDD (le module est considéré en ligne tant que dernière_lecture + veille n’est pas dépassé).
     * Bornes : min 60 s, max 86400 s (24 h).
     */
    private function resolveOnlineThresholdSeconds(): int
    {
        $output = $this->outputRepo->findByGpio(self::FREQ_WAKEUP_GPIO);
        if ($output === null || $output['state'] === null || $output['state'] === '') {
            return self::DEFAULT_ONLINE_THRESHOLD_SECONDS;
        }
        $seconds = (int) $output['state'];
        if ($seconds <= 0) {
            return self::DEFAULT_ONLINE_THRESHOLD_SECONDS;
        }
        return max(60, min(86400, $seconds));
    }
}
