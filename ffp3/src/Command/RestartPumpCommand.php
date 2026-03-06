<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\Database;
use App\Service\LogService;
use App\Service\PumpService;

/**
 * Commande pour redémarrer la pompe aquarium après un délai programmé
 * 
 * Cette commande vérifie s'il existe un flag de redémarrage programmé
 * et redémarre la pompe si le délai est écoulé.
 */
class RestartPumpCommand
{
    private LogService $logger;
    private PumpService $pumpService;

    // Délai avant redémarrage (en secondes)
    private const RESTART_DELAY = 300; // 5 minutes

    public function __construct()
    {
        $pdo = Database::getConnection();
        $this->logger = new LogService();
        $this->pumpService = new PumpService($pdo);
    }

    /**
     * Exécute la vérification et le redémarrage si nécessaire
     */
    public function execute(): void
    {
        $flagFile = sys_get_temp_dir() . '/pump_restart_scheduled.flag';

        // Vérifier si un redémarrage est programmé
        if (!file_exists($flagFile)) {
            // Pas de redémarrage programmé, rien à faire
            return;
        }

        // Lire le timestamp du flag
        $scheduledTime = (int) file_get_contents($flagFile);
        $currentTime = time();
        $elapsedTime = $currentTime - $scheduledTime;

        // Vérifier si le délai est écoulé
        if ($elapsedTime >= self::RESTART_DELAY) {
            $this->logger->info('Délai de redémarrage écoulé. Redémarrage de la pompe aquarium...');
            
            // Redémarrer la pompe
            $this->pumpService->runPompeAqua();
            
            // Supprimer le flag
            unlink($flagFile);
            
            $this->logger->info('Pompe aquarium redémarrée avec succès.');
        } else {
            // Délai non écoulé
            $remainingTime = self::RESTART_DELAY - $elapsedTime;
            $this->logger->info("Redémarrage programmé dans {$remainingTime} secondes.");
        }
    }
}

