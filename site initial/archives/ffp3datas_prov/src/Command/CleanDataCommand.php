<?php

namespace App\Command;

use App\Config\Database;
use App\Service\LogService;
use App\Service\PumpService;
use App\Service\SensorDataService;
use App\Service\SensorStatisticsService;

/**
 * Commande pour nettoyer les données des capteurs et gérer l'état des pompes
 */
class CleanDataCommand
{
    private LogService $logger;
    private SensorDataService $sensorDataService;
    private PumpService $pumpService;
    private SensorStatisticsService $statsService;

    public function __construct()
    {
        $pdo = Database::getConnection();
        $this->logger = new LogService();
        $this->sensorDataService = new SensorDataService($pdo, $this->logger);
        $this->pumpService = new PumpService($pdo);
        $this->statsService = new SensorStatisticsService($pdo);
    }

    /**
     * Exécute la commande de nettoyage des données
     */
    public function execute(): void
    {
        // -----------------------------------------------------------------
        // Mécanisme de verrouillage pour empêcher deux crons concurrents ----
        // -----------------------------------------------------------------
        $lockPath = sys_get_temp_dir() . '/clean_data_command.lock';
        $lockHandle = fopen($lockPath, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            // Impossible d’obtenir le verrou → une instance tourne déjà
            $this->logger->warning('CleanDataCommand déjà en cours, sortie.');
            return;
        }

        // Écriture PID pour debug
        ftruncate($lockHandle, 0);
        fwrite($lockHandle, (string) getmypid());

        $this->logger->addEvent("Démarrage nettoyage des données");

        // Vérifier l'état des pompes
        $this->checkPumpsState();

        // Nettoyer les données des capteurs
        $stats = $this->sensorDataService->cleanAllSensorData();
        
        // Afficher les statistiques de nettoyage
        foreach ($stats as $type => $count) {
            $this->logger->addName("$type: ");
            $this->logger->addTask("$count valeurs supprimées");
        }

        // Vérifier le niveau d'eau et ajuster les pompes si nécessaire
        $this->checkWaterLevels();

        $this->logger->addEvent("Fin du nettoyage des données");

        // Libération du verrou
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    /**
     * Vérifier et journaliser l'état des pompes
     */
    private function checkPumpsState(): void
    {
        // Vérifier l'état de la pompe aquarium
        $etatPompeAqua = $this->pumpService->getAquaPumpState();
        $this->logger->addName("Pompe aquarium: ");
        $this->logger->addTask($etatPompeAqua);

        // Vérifier l'état de la pompe réservoir
        $etatPompeTank = $this->pumpService->getTankPumpState();
        $this->logger->addName("Pompe réserve: ");
        $this->logger->addTask($etatPompeTank);

        // Vérifier l'état du mode reset
        $etatResetMode = $this->pumpService->getResetModeState();
        $this->logger->addName("Reset mode: ");
        $this->logger->addTask($etatResetMode);
    }

    /**
     * Vérifier les niveaux d'eau et ajuster les pompes si nécessaire
     */
    private function checkWaterLevels(): void
    {
        // Calculer la période pour les statistiques
        $end = date('Y-m-d H:i:s');
        $start = date('Y-m-d H:i:s', strtotime('-1 hour'));

        // Obtenir le dernier niveau d'eau de l'aquarium
        $lastWaterLevel = $this->statsService->min('EauAquarium', $start, $end);
        $this->logger->addName("Dernier niveau d'eau aquarium: ");
        $this->logger->addTask($lastWaterLevel);

        // Seuil configurable (AQUA_LOW_LEVEL_THRESHOLD, défaut 7)
        $lowThreshold = (float) ($_ENV['AQUA_LOW_LEVEL_THRESHOLD'] ?? 7);

        // Si le niveau d'eau est trop bas, arrêter la pompe du réservoir
        if ($lastWaterLevel !== null && $lastWaterLevel < $lowThreshold) {
            $this->pumpService->stopPompeTank();
            $this->logger->addEvent("ALERTE: Niveau d'eau aquarium trop bas ($lastWaterLevel) - Arrêt pompe réservoir");
            
            // Envoyer une alerte par email
            $this->logger->sendAlertEmail(
                "Alerte niveau d'eau aquarium",
                "Le niveau d'eau de l'aquarium est trop bas ($lastWaterLevel). La pompe du réservoir a été arrêtée automatiquement.",
                ['niveau' => $lastWaterLevel, 'seuil' => $lowThreshold]
            );
        }

        // Vérifier la déviation standard des mesures d'eau de l'aquarium
        $stddev = $this->statsService->stddev('EauAquarium', $start, $end);
        $this->logger->addName("Déviation standard niveau eau aquarium: ");
        $this->logger->addTask($stddev);
    }
} 