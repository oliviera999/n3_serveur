<?php

namespace App\Command;

use App\Config\Database;
use App\Repository\SensorReadRepository;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Service\PumpService;
use App\Service\SensorDataService;
use App\Service\SensorStatisticsService;
use App\Service\SystemHealthService;

class ProcessTasksCommand
{
    private LogService $logger;
    private SensorDataService $sensorDataService;
    private PumpService $pumpService;
    private SensorStatisticsService $statsService;
    private NotificationService $notifier;
    private SensorReadRepository $sensorReadRepo;
    private SystemHealthService $healthService;

    // Seuils configurables
    private float $aquaLowThreshold;
    private float $stddevThreshold;

    public function __construct()
    {
        // Initialisation des services (Injection de dépendances manuelle)
        $pdo = Database::getConnection();
        $this->logger = new LogService();
        $this->sensorDataService = new SensorDataService($pdo, $this->logger);
        $this->pumpService = new PumpService($pdo);
        $this->statsService = new SensorStatisticsService($pdo);
        $this->sensorReadRepo = new SensorReadRepository($pdo);
        $this->notifier = new NotificationService($this->logger);
        $this->healthService = new SystemHealthService($this->sensorReadRepo, $this->notifier, $this->logger);

        // Chargement des seuils depuis l'environnement
        $this->aquaLowThreshold = (float) ($_ENV['AQUA_LOW_LEVEL_THRESHOLD'] ?? 7.0);
        $this->stddevThreshold  = (float) ($_ENV['TIDE_STDDEV_THRESHOLD'] ?? 1.0);
    }

    /**
     * Point d'entrée de la commande.
     */
    public function execute(): void
    {
        // -----------------------------------------------------------------
        // Verrou d’exécution pour éviter les crons simultanés --------------
        // -----------------------------------------------------------------
        $lockPath = sys_get_temp_dir() . '/process_tasks_command.lock';
        $lockHandle = fopen($lockPath, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $this->logger->warning('ProcessTasksCommand déjà en cours, sortie.');
            return;
        }

        ftruncate($lockHandle, 0);
        fwrite($lockHandle, (string) getmypid());

        $this->logger->info('--- Début du traitement des tâches CRON ---');

        // 1. Nettoyage des données aberrantes
        $this->logger->info('Lancement du nettoyage des données...');
        $this->sensorDataService->cleanAllSensorData();
        $this->logger->info('Nettoyage des données terminé.');

        // 2. Vérification du risque d'inondation
        $this->checkFloodRisk();

        // 3. Vérification du bon fonctionnement des marées
        $this->checkTideSystem();

        // 4. Vérification de l'état général du système
        $this->healthService->checkOnlineStatus();
        $this->healthService->checkTankLevel();

        $this->logger->info('--- Fin du traitement des tâches CRON ---');

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    /**
     * Vérifie si le niveau de l'aquarium est trop haut et coupe la pompe de la réserve si besoin.
     */
    private function checkFloodRisk(): void
    {
        $lastReading = $this->sensorReadRepo->getLastReadings();
        $lastAquaLevel = $lastReading['EauAquarium'] ?? null;

        if ($lastAquaLevel !== null && $lastAquaLevel < $this->aquaLowThreshold) {
            $this->logger->warning("Risque d'inondation détecté (niveau eau: {$lastAquaLevel}). Arrêt de la pompe du réservoir.");
            $this->pumpService->stopPompeTank();
            $this->notifier->notifyFloodRisk();
        }
    }

    /**
     * Vérifie si le système de marée fonctionne correctement en analysant la déviation standard.
     * Si la déviation est trop faible, la pompe est arrêtée et un flag est créé pour le redémarrage.
     */
    private function checkTideSystem(): void
    {
        $stddev = $this->statsService->stddevOnLastReadings('EauAquarium');

        if ($stddev !== null && $stddev < $this->stddevThreshold) {
            $this->logger->warning("Problème de marée détecté (stddev: {$stddev}). Arrêt de la pompe de l'aquarium.");
            $this->pumpService->stopPompeAqua();
            
            // Créer un flag file pour indiquer qu'un redémarrage est programmé dans 5 minutes
            $flagFile = sys_get_temp_dir() . '/pump_restart_scheduled.flag';
            file_put_contents($flagFile, time());
            
            $this->logger->info('Pompe aquarium arrêtée. Redémarrage programmé dans 5 minutes via prochain CRON.');
            $this->notifier->notifyMareesProblem();
        }
    }
} 