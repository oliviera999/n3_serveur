<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\Database;
use App\Service\LogService;
use App\Service\PumpService;

/**
 * Redémarre la pompe aquarium après un délai programmé (flag file).
 * Appelée en première phase par CronOrchestrator.
 */
class RestartPumpCommand
{
    private const RESTART_DELAY = 300;

    private LogService $logger;
    private PumpService $pumpService;
    private string $flagFile;
    private ?int $currentTimeOverride;

    public function __construct(
        ?PumpService $pumpService = null,
        ?LogService $logger = null,
        ?string $flagFile = null,
        ?int $currentTimeOverride = null,
    ) {
        if ($pumpService === null || $logger === null) {
            $pdo = Database::getConnection();
        }
        $this->logger = $logger ?? new LogService();
        $this->pumpService = $pumpService ?? new PumpService($pdo);
        $this->flagFile = $flagFile ?? sys_get_temp_dir() . '/pump_restart_scheduled.flag';
        $this->currentTimeOverride = $currentTimeOverride;
    }

    public function execute(): void
    {
        if (!file_exists($this->flagFile)) {
            return;
        }

        $scheduledTime = (int) file_get_contents($this->flagFile);
        $currentTime = $this->currentTimeOverride ?? time();
        $elapsedTime = $currentTime - $scheduledTime;

        if ($elapsedTime >= self::RESTART_DELAY) {
            $this->logger->info('Délai de redémarrage écoulé. Redémarrage de la pompe aquarium...');
            $this->pumpService->runPompeAqua();
            unlink($this->flagFile);
            $this->logger->info('Pompe aquarium redémarrée avec succès.');
            return;
        }

        $remainingTime = self::RESTART_DELAY - $elapsedTime;
        $this->logger->info("Redémarrage programmé dans {$remainingTime} secondes.");
    }
}
