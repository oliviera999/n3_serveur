<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Repository\EnergieSensorRepository;

/**
 * Fournisseur temps réel du banc énergie (3 × INA226 : panneau / batterie / conso).
 *
 * Famille « mesure seule » : ni sorties (GPIO) ni alertes — getOutputsState() et
 * getActiveAlerts() renvoient des listes vides. Les lectures suivent le même format
 * que {@see AbstractSensorRealtimeDataProvider} (timestamp, reading_time, sensors{col: valeur}).
 *
 * Santé : le firmware poste un agrégat toutes les 10 s ; le module est « en ligne »
 * si la dernière mesure date de moins de 90 s (9 envois manqués tolérés). L'uptime
 * est calculé sur 1 jour avec 86400 / 10 = 8640 lectures attendues.
 */
class EnergieRealtimeDataProvider implements RealtimeDataProviderInterface
{
    use RealtimeHealthTrait;

    /** Cadence d'envoi du firmware (agrégat POST toutes les 10 s). */
    public const POST_INTERVAL_SECONDS = 10;
    /** Au-delà, le module est considéré hors ligne. */
    public const ONLINE_THRESHOLD_SECONDS = 90;
    /** Fenêtre de calcul de l'uptime (jours). */
    public const UPTIME_DAYS = 1;

    public function __construct(
        private EnergieSensorRepository $sensorRepo,
    ) {
    }

    public function getLatestReadings(): array
    {
        $row = $this->sensorRepo->getLatest();
        if ($row === null) {
            return ['timestamp' => time(), 'reading_time' => null, 'sensors' => []];
        }

        $readingTime = (string) $row['reading_time'];
        $ts = strtotime($readingTime);

        return [
            'timestamp' => $ts !== false ? $ts : time(),
            'reading_time' => $readingTime,
            'sensors' => $this->rowToSensors($row),
        ];
    }

    public function getReadingsSince(int $sinceTimestamp): array
    {
        $sinceDate = date('Y-m-d H:i:s', $sinceTimestamp);
        $rows = $this->sensorRepo->fetchBetween($sinceDate, date('Y-m-d H:i:s'));

        $result = [];
        foreach ($rows as $row) {
            $readingTime = (string) $row['reading_time'];
            $ts = strtotime($readingTime);
            if ($ts === false) {
                continue;
            }
            $result[] = [
                'timestamp' => $ts,
                'reading_time' => $readingTime,
                'sensors' => $this->rowToSensors($row),
            ];
        }

        return $result;
    }

    public function getSystemHealth(): array
    {
        $lastReadingDate = $this->sensorRepo->getLastReadingDate();
        if ($lastReadingDate === null) {
            return $this->emptySystemHealth(null);
        }

        $lastTs = strtotime($lastReadingDate);
        $secondsAgo = $lastTs !== false ? time() - $lastTs : null;
        $isOnline = $secondsAgo !== null && $secondsAgo < self::ONLINE_THRESHOLD_SECONDS;

        return $this->assembleSystemHealth(
            $isOnline,
            $lastReadingDate,
            $lastTs !== false ? $lastTs : null,
            $secondsAgo,
            $this->calculateUptime(),
            $this->sensorRepo->countReadingsToday(),
            null,
            $this->moduleUptimeSecondsFromDate($this->sensorRepo->getFirstReadingDate()),
        );
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowToSensors(array $row): array
    {
        $sensors = [];
        foreach ($this->sensorRepo->getSensorColumns() as $col) {
            $sensors[$col] = $row[$col] ?? null;
        }

        return $sensors;
    }

    /**
     * Uptime (%) sur {@see self::UPTIME_DAYS} jour(s) : lectures reçues / lectures attendues
     * (une toutes les {@see self::POST_INTERVAL_SECONDS} s), plafonné à 100 %.
     */
    private function calculateUptime(): float
    {
        $windowSeconds = self::UPTIME_DAYS * 86400;
        $start = date('Y-m-d H:i:s', time() - $windowSeconds);
        $end = date('Y-m-d H:i:s');
        $actual = $this->sensorRepo->countReadingsBetween($start, $end);
        $expected = $windowSeconds / self::POST_INTERVAL_SECONDS;

        return $this->uptimePercentage((float) $actual, (float) $expected);
    }
}
