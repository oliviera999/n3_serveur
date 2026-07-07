<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\Database;
use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\HeartbeatMonitorRepository;
use App\Repository\OutputMonitorRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\DeviceHealthService;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Service\OfflineThresholdResolver;
use App\Service\PumpService;
use App\Service\SensorDataService;
use App\Service\SensorStatisticsService;
use App\Service\SystemHealthService;

/**
 * Orchestrateur unique des tâches CRON applicatives FFP3.
 * Point d'entrée : run-cron.php (crontab toutes les 5 min).
 */
class CronOrchestrator
{
    /** Distance capteur→surface (mm) au-delà de laquelle l'eau est considérée basse (aligné aqThreshold firmware, 18 cm). */
    private const DEFAULT_AQUA_LOW_LEVEL_THRESHOLD_MM = 180.0;

    /** GPIO 102 (FFP3) : seuil aquarium firmware, exprimé en CENTIMÈTRES en BDD. */
    private const AQ_THRESHOLD_GPIO = 102;
    /** GPIO server-only 129 (FFP3) : seuil d'écart-type marées. */
    private const TIDE_STDDEV_GPIO = 129;
    /** Conversion cm→mm (EauAquarium est stocké en mm ; le seuil BDD GPIO 102 est en cm). */
    private const MM_PER_CM = 10.0;
    /** Forfait hors-ligne (s) de repli quand aucun résolveur dérivé n'est disponible. */
    private const DEFAULT_OFFLINE_FALLBACK_SECONDS = 3600;

    private const LOCK_FILENAME = 'cron_orchestrator.lock';
    private const HOURLY_STATE_FILENAME = 'cron_last_hourly.timestamp';
    private const PUMP_RESTART_FLAG_FILENAME = 'pump_restart_scheduled.flag';

    private LogService $logger;
    private SensorDataService $sensorDataService;
    private PumpService $pumpService;
    private SensorStatisticsService $statsService;
    private NotificationService $notifier;
    private SensorReadRepository $sensorReadRepo;
    private SystemHealthService $healthService;
    private DeviceHealthService $deviceHealthService;
    private RestartPumpCommand $restartPumpCommand;
    private ?OutputRepository $outputRepo;
    private ?OfflineThresholdResolver $offlineResolver;

    private float $aquaLowThreshold;
    private float $stddevThreshold;
    private int $hourlyIntervalSeconds;

    private string $lockDir;
    private string $stateDir;
    private string $pumpRestartFlagFile;

    public function __construct(
        ?LogService $logger = null,
        ?SensorDataService $sensorDataService = null,
        ?PumpService $pumpService = null,
        ?SensorStatisticsService $statsService = null,
        ?NotificationService $notifier = null,
        ?SensorReadRepository $sensorReadRepo = null,
        ?SystemHealthService $healthService = null,
        ?DeviceHealthService $deviceHealthService = null,
        ?RestartPumpCommand $restartPumpCommand = null,
        ?OutputRepository $outputRepo = null,
        ?OfflineThresholdResolver $offlineResolver = null,
        ?string $lockDir = null,
        ?string $stateDir = null,
        ?string $pumpRestartFlagFile = null,
    ) {
        $needsDatabase = $logger === null
            || $sensorDataService === null
            || $pumpService === null
            || $statsService === null
            || $notifier === null
            || $sensorReadRepo === null
            || $healthService === null
            || $deviceHealthService === null
            || $restartPumpCommand === null;

        $pdo = $needsDatabase ? Database::getConnection() : null;

        $this->logger = $logger ?? new LogService();
        $this->sensorDataService = $sensorDataService ?? new SensorDataService($pdo, $this->logger);
        $this->pumpService = $pumpService ?? new PumpService($pdo);
        $this->statsService = $statsService ?? new SensorStatisticsService($pdo);
        $this->notifier = $notifier ?? new NotificationService($this->logger);
        $this->sensorReadRepo = $sensorReadRepo ?? new SensorReadRepository($pdo);

        // Lecture des seuils pilotés en BDD. Restent null en contexte de test (toutes les
        // dépendances injectées, pas de PDO) : les seuils retombent alors sur `.env` / défauts.
        $this->outputRepo = $outputRepo ?? ($pdo !== null ? new OutputRepository($pdo) : null);
        $this->offlineResolver = $offlineResolver
            ?? ($pdo !== null ? new OfflineThresholdResolver(new OutputMonitorRepository($pdo)) : null);

        $this->healthService = $healthService ?? new SystemHealthService(
            $this->sensorReadRepo,
            $this->notifier,
            $this->logger,
            $this->outputRepo
        );
        $this->deviceHealthService = $deviceHealthService ?? new DeviceHealthService(
            new HeartbeatMonitorRepository($pdo ?? Database::getConnection()),
            $this->notifier,
            $this->logger,
            null,
            null,
            $this->offlineResolver
        );

        $this->aquaLowThreshold = (float) (
            $_ENV['AQUA_LOW_LEVEL_THRESHOLD'] ?? self::DEFAULT_AQUA_LOW_LEVEL_THRESHOLD_MM
        );
        $this->stddevThreshold = (float) ($_ENV['TIDE_STDDEV_THRESHOLD'] ?? 1.0);
        $this->hourlyIntervalSeconds = (int) ($_ENV['CRON_HOURLY_INTERVAL_SECONDS'] ?? 3600);

        $projectRoot = dirname(__DIR__, 2);
        $this->lockDir = $lockDir ?? sys_get_temp_dir();
        $this->stateDir = $stateDir ?? $projectRoot . '/var/cache';
        $this->pumpRestartFlagFile = $pumpRestartFlagFile
            ?? sys_get_temp_dir() . '/' . self::PUMP_RESTART_FLAG_FILENAME;

        $this->restartPumpCommand = $restartPumpCommand ?? new RestartPumpCommand(
            $this->pumpService,
            $this->logger,
            $this->pumpRestartFlagFile
        );
    }

    public function execute(): void
    {
        $lockHandle = $this->acquireLock();
        if ($lockHandle === null) {
            $this->logger->warning('CronOrchestrator déjà en cours, sortie.');
            return;
        }

        try {
            $this->logger->info('--- Début orchestrateur CRON ---');

            $this->restartPumpCommand->execute();
            $this->runFrequentTasks();

            if ($this->isHourlyDue()) {
                $this->runHourlyTasks();
                $this->markHourlyRun();
            }

            $this->logger->info('--- Fin orchestrateur CRON ---');
        } finally {
            $this->releaseLock($lockHandle);
        }
    }

    /**
     * @return resource|null
     */
    protected function acquireLock()
    {
        $lockPath = $this->getLockPath();
        $lockHandle = fopen($lockPath, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if ($lockHandle !== false) {
                fclose($lockHandle);
            }
            return null;
        }

        ftruncate($lockHandle, 0);
        fwrite($lockHandle, (string) getmypid());

        return $lockHandle;
    }

    /**
     * @param resource $lockHandle
     */
    protected function releaseLock($lockHandle): void
    {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    protected function getLockPath(): string
    {
        return $this->lockDir . '/' . self::LOCK_FILENAME;
    }

    protected function getHourlyStatePath(): string
    {
        return $this->stateDir . '/' . self::HOURLY_STATE_FILENAME;
    }

    protected function isHourlyDue(): bool
    {
        $statePath = $this->getHourlyStatePath();
        if (!is_file($statePath)) {
            return true;
        }

        $lastRun = (int) file_get_contents($statePath);
        return (time() - $lastRun) >= $this->hourlyIntervalSeconds;
    }

    protected function markHourlyRun(): void
    {
        if (!is_dir($this->stateDir)) {
            mkdir($this->stateDir, 0755, true);
        }

        file_put_contents($this->getHourlyStatePath(), (string) time());
    }

    protected function runFrequentTasks(): void
    {
        $this->logger->addEvent('Démarrage tâches fréquentes CRON');
        $this->logPumpStates();

        $stats = $this->sensorDataService->cleanAllSensorData();
        foreach ($stats as $type => $count) {
            $this->logger->addName("$type: ");
            $this->logger->addTask("$count valeurs supprimées");
        }

        $this->checkLowWaterLevel();
        $this->checkTideSystem();
        $this->logHourlyStddev();

        $this->logger->addEvent('Fin tâches fréquentes CRON');
    }

    protected function runHourlyTasks(): void
    {
        $this->logger->info('Lancement des tâches horaires CRON...');
        $this->healthService->checkOnlineStatus($this->resolveFfp3OfflineThresholdSeconds());
        $this->healthService->checkTankLevel();
        // Supervision « appareil silencieux » généralisée à toutes les familles (FFP3/N3PP/MSP1).
        $this->deviceHealthService->checkAllFamilies();
        // Envoie un unique e-mail regroupant les alertes de faible sévérité accumulées.
        $this->notifier->flushDigest();
        $this->logger->info('Tâches horaires CRON terminées.');
    }

    private function logPumpStates(): void
    {
        $this->logger->addName('Pompe aquarium: ');
        $this->logger->addTask((string) $this->pumpService->getAquaPumpState());

        $this->logger->addName('Pompe réserve: ');
        $this->logger->addTask((string) $this->pumpService->getTankPumpState());

        $this->logger->addName('Reset mode: ');
        $this->logger->addTask((string) $this->pumpService->getResetModeState());
    }

    private function checkLowWaterLevel(): void
    {
        $lastReading = $this->sensorReadRepo->getLastReadings();
        $lastWaterLevel = $lastReading['EauAquarium'] ?? null;

        $threshold = $this->resolveAquaLowThresholdMm();

        $this->logger->addName("Dernier niveau d'eau aquarium: ");
        $this->logger->addTask((string) $lastWaterLevel);

        // EauAquarium = distance capteur→surface en mm : valeur élevée = eau basse (comme côté firmware).
        if ($lastWaterLevel === null || $lastWaterLevel <= $threshold) {
            return;
        }

        $this->pumpService->stopPompeTank();
        $this->logger->addEvent(
            "ALERTE: Niveau d'eau aquarium trop bas (distance {$lastWaterLevel} mm) - Arrêt pompe réservoir"
        );

        $message = sprintf(
            "La distance capteur→surface de l'aquarium a atteint %.0f mm (seuil %.0f mm).\n"
            . "L'eau est considérée trop basse. La pompe réservoir a été arrêtée automatiquement pour éviter une panne sèche.",
            $lastWaterLevel,
            $threshold
        );
        $this->notifier->sendAlert(
            Severity::Critical,
            NotificationCategory::Hydraulic,
            'FFP3',
            "Niveau d'eau aquarium bas",
            $message,
            'ffp3:water-low'
        );
    }

    private function checkTideSystem(): void
    {
        $stddev = $this->statsService->stddevOnLastReadings('EauAquarium');

        if ($stddev === null || $stddev >= $this->resolveTideStddevThreshold()) {
            return;
        }

        $this->logger->warning("Problème de marée détecté (stddev: {$stddev}). Arrêt de la pompe de l'aquarium.");
        $this->pumpService->stopPompeAqua();
        file_put_contents($this->pumpRestartFlagFile, (string) time());
        $this->logger->info('Pompe aquarium arrêtée. Redémarrage programmé dans 5 minutes via prochain CRON.');
        $this->notifier->notifyMareesProblem();
    }

    /**
     * Seuil aquarium bas (mm). Priorité BDD : GPIO 102 (seuil firmware en cm) × 10 = mm.
     * Repli sur `.env` AQUA_LOW_LEVEL_THRESHOLD / défaut 180 mm si BDD absente/invalide.
     */
    private function resolveAquaLowThresholdMm(): float
    {
        $cm = $this->readPositiveOutputFloat(self::AQ_THRESHOLD_GPIO);
        if ($cm !== null) {
            return $cm * self::MM_PER_CM;
        }

        return $this->aquaLowThreshold;
    }

    /**
     * Seuil d'écart-type marées. Priorité BDD : GPIO server-only 129. Repli `.env`
     * TIDE_STDDEV_THRESHOLD / défaut 1.0.
     */
    private function resolveTideStddevThreshold(): float
    {
        return $this->readPositiveOutputFloat(self::TIDE_STDDEV_GPIO) ?? $this->stddevThreshold;
    }

    /**
     * Seuil hors-ligne FFP3 (s) dérivé du temps de veille en BDD (facteur nuit compris),
     * ou forfait 3600 s si aucun résolveur n'est disponible (contexte de test).
     */
    private function resolveFfp3OfflineThresholdSeconds(): int
    {
        return $this->offlineResolver?->resolveForFamily('FFP3') ?? self::DEFAULT_OFFLINE_FALLBACK_SECONDS;
    }

    /**
     * Lit un output FFP3 (environnement courant) et retourne sa valeur flottante si > 0,
     * sinon null (repo absent, ligne vide / non numérique, ou valeur ≤ 0).
     */
    private function readPositiveOutputFloat(int $gpio): ?float
    {
        if ($this->outputRepo === null) {
            return null;
        }

        try {
            $row = $this->outputRepo->findByGpio($gpio);
        } catch (\Throwable $e) {
            $this->logger->addEvent('Lecture output GPIO ' . $gpio . ' impossible: ' . $e->getMessage());

            return null;
        }

        if ($row === null || !isset($row['state']) || $row['state'] === '' || !is_numeric($row['state'])) {
            return null;
        }

        $value = (float) $row['state'];

        return $value > 0 ? $value : null;
    }

    private function logHourlyStddev(): void
    {
        $end = date('Y-m-d H:i:s');
        $start = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $stddev = $this->statsService->stddev('EauAquarium', $start, $end);
        $this->logger->addName('Déviation standard niveau eau aquarium: ');
        $this->logger->addTask((string) $stddev);
    }
}
