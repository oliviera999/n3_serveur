<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\Database;
use App\Notification\NotificationCategory;
use App\Notification\NotificationPolicyResolver;
use App\Notification\Severity;
use App\Repository\HeartbeatMonitorRepository;
use App\Repository\MspSensorRepository;
use App\Repository\N3ppSensorRepository;
use App\Repository\NotificationPolicyRepository;
use App\Repository\OutputMonitorRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\Availability\AvailabilityNotifier;
use App\Service\DerivedAlert\DerivedAlertStateStore;
use App\Service\DerivedAlert\Ffp3DerivedAlertService;
use App\Service\DerivedAlert\MspDerivedAlertService;
use App\Service\DerivedAlert\N3ppDerivedAlertService;
use App\Service\DeviceHealthService;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Service\OfflineThresholdResolver;
use App\Service\OperationalSettingsService;
use App\Service\PumpService;
use App\Service\SensorDataService;
use App\Service\SensorStatisticsService;
use App\Service\SystemHealthService;
use App\Util\JsonFileStore;
use PDO;

/**
 * Orchestrateur unique des tâches CRON applicatives FFP3.
 * Point d'entrée : run-cron.php (crontab toutes les 1 min — Phase 1 arbitrage mails :
 * le serveur est l'émetteur primaire des alertes, latence cible ≤ 1 min).
 *
 * Les runs qui se chevauchent sont ignorés proprement via un verrou flock non
 * bloquant (LOCK_NB) : à cadence 1 min, un run lent ne s'empile pas.
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

    /**
     * Fenêtre glissante (s) du nettoyage de données (SensorDataService).
     * Le CRON tourne chaque minute : ne balayer que les lignes des ~15 dernières
     * minutes suffit largement (marge pour un run manqué/lent) et permet à l'UPDATE
     * de s'appuyer sur l'index reading_time au lieu d'un full-scan à chaque tick.
     * Les lignes plus anciennes ont déjà été nettoyées aux passages précédents.
     */
    private const CLEANING_WINDOW_SECONDS = 900;

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
    private ?Ffp3DerivedAlertService $ffp3DerivedAlerts;
    private ?N3ppDerivedAlertService $n3ppDerivedAlerts;
    private ?MspDerivedAlertService $mspDerivedAlerts;

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
        ?Ffp3DerivedAlertService $ffp3DerivedAlerts = null,
        ?N3ppDerivedAlertService $n3ppDerivedAlerts = null,
        ?MspDerivedAlertService $mspDerivedAlerts = null,
        ?string $lockDir = null,
        ?string $stateDir = null,
        ?string $pumpRestartFlagFile = null,
        ?OperationalSettingsService $operationalSettings = null,
        ?PDO $pdo = null,
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

        // Connexion fournie par le container DI en priorité. Sans elle, tous les
        // collaborateurs conditionnés par `$pdo` (seuils BDD, résolveur hors-ligne,
        // alertes dérivées) resteraient null et le CRON retomberait silencieusement
        // sur `.env` (cf. config/dependencies.php : la fabrique injecte les services,
        // donc `$needsDatabase` est faux et aucune connexion n'était ouverte ici).
        $pdo ??= $needsDatabase ? Database::getConnection() : null;

        // Replis utilisés hors container (construction directe) : leur passer
        // $operationalSettings, sinon ces services perdent la lecture des réglages BDD
        // exactement comme le faisait le câblage DI avant la 6.28.0.
        $this->logger = $logger ?? new LogService($operationalSettings);
        $this->sensorDataService = $sensorDataService
            ?? new SensorDataService($pdo, $this->logger, $operationalSettings);
        $this->pumpService = $pumpService ?? new PumpService($pdo);
        $this->statsService = $statsService ?? new SensorStatisticsService($pdo);
        $this->notifier = $notifier ?? new NotificationService(
            $this->logger,
            policyResolver: $pdo !== null
                ? NotificationPolicyResolver::fromEnv(new NotificationPolicyRepository($pdo), $operationalSettings)
                : null
        );
        $this->sensorReadRepo = $sensorReadRepo ?? new SensorReadRepository($pdo, $operationalSettings);

        // Lecture des seuils pilotés en BDD. Restent null en contexte de test (toutes les
        // dépendances injectées, pas de PDO) : les seuils retombent alors sur `.env` / défauts.
        $this->outputRepo = $outputRepo ?? ($pdo !== null ? new OutputRepository($pdo) : null);
        $this->offlineResolver = $offlineResolver
            ?? ($pdo !== null ? new OfflineThresholdResolver(new OutputMonitorRepository($pdo)) : null);

        $projectRoot = dirname(__DIR__, 2);
        $this->lockDir = $lockDir ?? sys_get_temp_dir();
        $this->stateDir = $stateDir ?? $projectRoot . '/var/cache';

        // Machine à états « disponibilité » partagée par les deux supervisions hors-ligne :
        // un e-mail à la perte de l'appareil, un à son retour, aucun rappel entre les deux.
        $availability = new AvailabilityNotifier(
            $this->notifier,
            $this->logger,
            new JsonFileStore($this->stateDir . '/availability_state.json')
        );

        $this->healthService = $healthService ?? new SystemHealthService(
            $this->sensorReadRepo,
            $this->notifier,
            $this->logger,
            $this->outputRepo,
            $operationalSettings,
            $availability
        );
        $this->deviceHealthService = $deviceHealthService ?? new DeviceHealthService(
            new HeartbeatMonitorRepository($pdo ?? Database::getConnection()),
            $this->notifier,
            $this->logger,
            null,
            null,
            $this->offlineResolver,
            $operationalSettings,
            null,
            $availability
        );

        $this->aquaLowThreshold = $operationalSettings?->float(
            'AQUA_LOW_LEVEL_THRESHOLD',
            self::DEFAULT_AQUA_LOW_LEVEL_THRESHOLD_MM
        ) ?? (float) (
            $_ENV['AQUA_LOW_LEVEL_THRESHOLD'] ?? self::DEFAULT_AQUA_LOW_LEVEL_THRESHOLD_MM
        );
        $this->stddevThreshold = $operationalSettings?->float('TIDE_STDDEV_THRESHOLD', 1.0)
            ?? (float) ($_ENV['TIDE_STDDEV_THRESHOLD'] ?? 1.0);
        $this->hourlyIntervalSeconds = $operationalSettings?->int('CRON_HOURLY_INTERVAL_SECONDS', 3600)
            ?? (int) ($_ENV['CRON_HOURLY_INTERVAL_SECONDS'] ?? 3600);

        $this->pumpRestartFlagFile = $pumpRestartFlagFile
            ?? sys_get_temp_dir() . '/' . self::PUMP_RESTART_FLAG_FILENAME;

        $this->restartPumpCommand = $restartPumpCommand ?? new RestartPumpCommand(
            $this->pumpService,
            $this->logger,
            $this->pumpRestartFlagFile
        );

        // Phase 2 arbitrage mails : alertes dérivées du POST (serveur émetteur primaire).
        // Construites uniquement quand une connexion BDD est disponible (comme le
        // résolveur hors-ligne) : en contexte de test tout-mock, elles restent null.
        $this->ffp3DerivedAlerts = $ffp3DerivedAlerts
            ?? ($pdo !== null && $this->outputRepo !== null ? new Ffp3DerivedAlertService(
                $this->sensorReadRepo,
                $this->outputRepo,
                $this->notifier,
                $this->logger,
                new DerivedAlertStateStore($this->stateDir . '/derived_alerts_ffp3.json'),
                null,
                $operationalSettings
            ) : null);
        $this->n3ppDerivedAlerts = $n3ppDerivedAlerts
            ?? ($pdo !== null ? new N3ppDerivedAlertService(
                new N3ppSensorRepository($pdo),
                $this->notifier,
                $this->logger,
                new DerivedAlertStateStore($this->stateDir . '/derived_alerts_n3pp.json')
            ) : null);
        $this->mspDerivedAlerts = $mspDerivedAlerts
            ?? ($pdo !== null ? new MspDerivedAlertService(
                new MspSensorRepository($pdo),
                $this->notifier,
                $this->logger,
                new DerivedAlertStateStore($this->stateDir . '/derived_alerts_msp1.json'),
                $operationalSettings
            ) : null);
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

        // Alertes et décisions hydrauliques AVANT le nettoyage des valeurs aberrantes.
        // EauAquarium est une distance capteur→surface : une valeur faible = eau haute
        // (zone trop-plein). Si le CRON nullifiait d'abord les distances < CLEAN_MIN,
        // checkFlood() voyait NULL et les débordements les plus sévères restaient muets
        // (debounce jamais armé). Même logique pour aquarium bas / marée / réserve.
        $this->checkLowWaterLevel();
        $this->checkTideSystem();
        // Phase 1 arbitrage mails : la réserve basse rejoint le bucket fréquent
        // (latence ≤ 1 min comme aquarium bas / marées). L'anti-spam est assuré par
        // AlertThrottler (clé ffp3:reserve-low, cooldown par sévérité).
        $this->healthService->checkTankLevel();
        // Phase 2 arbitrage mails : alertes dérivées du POST (trop-plein, chauffage,
        // sol sec, batterie n3pp/msp, redémarrage) — serveur émetteur primaire.
        $this->runDerivedAlerts();

        // Fenêtre glissante : le nettoyage ne balaie que les lignes récentes
        // (index reading_time) au lieu de toute la table à chaque minute.
        $cleaningSince = date('Y-m-d H:i:s', time() - self::CLEANING_WINDOW_SECONDS);
        $stats = $this->sensorDataService->cleanAllSensorData($cleaningSince);
        foreach ($stats as $type => $count) {
            $this->logger->addValue("$type: ");
            $this->logger->addTask("$count valeurs supprimées");
        }

        $this->logHourlyStddev();

        $this->logger->addEvent('Fin tâches fréquentes CRON');
    }

    /**
     * Exécute les services d'alertes dérivées, chacun isolé : une famille en erreur
     * (table absente, BDD partielle…) ne doit pas faire tomber le run CRON.
     */
    private function runDerivedAlerts(): void
    {
        $services = [
            'FFP3' => $this->ffp3DerivedAlerts,
            'N3PP' => $this->n3ppDerivedAlerts,
            'MSP1' => $this->mspDerivedAlerts,
        ];

        foreach ($services as $family => $service) {
            if ($service === null) {
                continue;
            }

            try {
                $service->run();
            } catch (\Throwable $e) {
                $this->logger->warning("Alertes dérivées {$family} en erreur : " . $e->getMessage());
            }
        }
    }

    protected function runHourlyTasks(): void
    {
        $this->logger->info('Lancement des tâches horaires CRON...');
        // Supervision « appareil silencieux » généralisée à toutes les familles (FFP3/N3PP/MSP1).
        // Passe EN PREMIER : l'incident « appareil » qu'elle ouvre fait taire l'alerte
        // « plus de données » ci-dessous pour la même panne FFP3 (un seul e-mail, pas deux).
        $this->deviceHealthService->checkAllFamilies();
        $this->healthService->checkOnlineStatus($this->resolveFfp3OfflineThresholdSeconds());
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

    /**
     * Aquarium bas (GPIO 102, « seuil de remplissage ») : ALERTE SEULE, aucune action pompe.
     *
     * Ce seuil est celui qui déclenche le remplissage CÔTÉ FIRMWARE (ffp5cs
     * `RefillStart::evaluate`) : `EauAquarium > seuil` = niveau trop bas = il faut remplir.
     * Le serveur ne pilote donc PAS la pompe réserve ici — il constate et notifie.
     *
     * Historique : jusqu'en 6.26.0 ce bloc appelait `PumpService::stopPompeTank()`, avec deux
     * défauts. (1) Intention inverse du firmware, qui démarre la pompe sur la même condition ;
     * la panne sèche est couverte par le seuil réserve (GPIO 103, verrou `RESERVOIR_LOW` de
     * l'ESP32), pas par celui-ci. (2) `stopPompeTank()` suivait alors la convention relais
     * actif-bas (`state = 1`), alors que `GET /api/outputs/state` sert la valeur brute et que
     * le firmware lit `1 = ON` (`gpio_parser.cpp`, front montant) : la « sécurité » commandait
     * en réalité un remplissage manuel — relancé à chaque minute tant que le niveau restait
     * bas, et hors du compteur d'essais qui verrouille la pompe inefficace. Cette seconde
     * inversion est corrigée depuis la 6.30.0 (`PumpService` aligné sur `1 = ON`).
     */
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

        $this->logger->addEvent(
            "ALERTE: Niveau d'eau aquarium bas (distance {$lastWaterLevel} mm) - remplissage attendu côté ESP32"
        );

        $message = sprintf(
            "La distance capteur→surface de l'aquarium a atteint %.0f mm (seuil de remplissage %.0f mm).\n"
            . "Le niveau est sous la consigne : l'ESP32 déclenche le remplissage depuis la réserve "
            . "(pompe réserve, durée « Remplissage »).\n"
            . 'Aucune action serveur — le pilotage de la pompe et ses sécurités (réserve basse, '
            . "remplissage inefficace) restent au firmware.\n"
            . "Si l'alerte persiste, vérifier la réserve, la pompe et le capteur.",
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
        // Phase 1 (CRON 1 min) : si un redémarrage de pompe est déjà programmé, ne pas
        // ré-évaluer. La pompe étant coupée, l'écart-type reste faible : chaque tick
        // réécrirait le flag avec un nouvel horodatage et repousserait le redémarrage
        // à l'infini (comportement sûr à 5 min par ordonnancement, cassé à 1 min).
        if (file_exists($this->pumpRestartFlagFile)) {
            $this->logger->info('Marée : redémarrage pompe déjà programmé, évaluation sautée.');
            return;
        }

        $stddev = $this->statsService->stddevOnLastReadings('EauAquarium');

        if ($stddev === null || $stddev >= $this->resolveTideStddevThreshold()) {
            return;
        }

        $this->logger->warning("Problème de marée détecté (stddev: {$stddev}). Arrêt de la pompe de l'aquarium.");
        $this->pumpService->stopPompeAqua();
        file_put_contents($this->pumpRestartFlagFile, (string) time());
        $this->logger->info('Pompe aquarium arrêtée. Redémarrage programmé dans 5 minutes (délai horodaté, indépendant de la cadence CRON).');
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
