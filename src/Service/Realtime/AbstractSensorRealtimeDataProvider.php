<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Repository\AbstractOutputRepository;
use App\Repository\AbstractSensorRepository;

/**
 * Classe abstraite pour les fournisseurs temps réel MSP1 et N3PP.
 * Factorise la logique commune : latest readings, readings since, system health,
 * outputs state, uptime. Chaque sous-classe fournit le board, les repos typés
 * et la liste des colonnes capteurs.
 */
abstract class AbstractSensorRealtimeDataProvider implements RealtimeDataProviderInterface
{
    use RealtimeHealthTrait;

    /** GPIO stockant FreqWakeUp (temps de veille en secondes) pour MSP et N3PP. */
    private const FREQ_WAKEUP_GPIO = 107;
    /** Seuil par défaut si FreqWakeUp absent ou invalide en BDD. */
    private const DEFAULT_ONLINE_THRESHOLD_SECONDS = 600;
    private const DEFAULT_UPTIME_DAYS = 30;

    public function __construct(
        protected AbstractSensorRepository $sensorRepo,
        protected AbstractOutputRepository $outputRepo,
        protected readonly int $board,
        private readonly int $expectedReadingIntervalMinutes = 2,
    ) {
    }

    abstract protected function getOutputsForBoard(): array;

    /**
     * Acquittement one-shot (13/108/109/110) + last_request — même logique que
     * getStateForFirmware(). À appeler seulement pour un client firmware authentifié
     * (X-Api-Key), pas pour le polling UI.
     *
     * Retourne l'état PLAT {gpio: state} destiné au firmware. Audit C1/C2 : les
     * firmwares n3pp/msp interrogent `/api/outputs/state` (format nested) mais lisent
     * des clés PLATES `myObject["110"]`… → la config n'était jamais appliquée et les
     * one-shots étaient acquittés sans jamais être vus. On ne jette plus ce résultat
     * (déjà calculé ici pour l'ack) : le contrôleur le fusionne à la racine de la
     * réponse pour les requêtes firmware.
     *
     * @return array<string, string> Clés = numéros GPIO (string), valeurs = état
     */
    public function acknowledgeFirmwareOneShots(): array
    {
        return $this->outputRepo->getStateForFirmware($this->board);
    }

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
            return $this->emptySystemHealth(null);
        }

        $lastTs = strtotime($lastReadingDate);
        $secondsAgo = time() - $lastTs;
        $thresholdSeconds = $this->resolveOnlineThresholdSeconds();
        $isOnline = $secondsAgo < $thresholdSeconds;

        return $this->assembleSystemHealth(
            $isOnline,
            $lastReadingDate,
            $lastTs !== false ? $lastTs : null,
            $secondsAgo,
            $this->calculateUptime(self::DEFAULT_UPTIME_DAYS),
            $this->sensorRepo->countReadingsToday(),
            null,
            $this->computeModuleUptimeSeconds(),
        );
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
        $actual = $this->sensorRepo->countReadingsBetween($start, $end);
        return $this->sensorUptimePercentage($days, $this->expectedReadingIntervalMinutes, $actual);
    }

    /**
     * Calcule le temps total (en secondes) depuis la première mesure enregistrée.
     */
    private function computeModuleUptimeSeconds(): ?int
    {
        return $this->moduleUptimeSecondsFromDate($this->sensorRepo->getFirstReadingDate());
    }
}
