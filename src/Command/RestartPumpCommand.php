<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\Database;
use App\Service\LogService;
use App\Service\PumpService;

/**
 * Redémarre la pompe aquarium après un délai programmé (flag file).
 * Appelée en première phase par CronOrchestrator.
 *
 * Le délai est HORODATÉ (le flag contient l'epoch de programmation ; on redémarre
 * quand `now - epoch >= RESTART_DELAY`), pas « au prochain tick » : le passage de la
 * crontab de 5 min à 1 min (Phase 1 arbitrage mails) ne change pas le délai effectif
 * de 5 minutes — seule la granularité de vérification s'affine.
 * Prérequis à cadence 1 min : le flag ne doit pas être réécrit tant qu'il est en
 * attente (garde dans CronOrchestrator::checkTideSystem), sinon le délai repartirait
 * de zéro à chaque tick.
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

    /**
     * @return bool true si la pompe a effectivement été redémarrée (flag consommé).
     *              Permet à CronOrchestrator de sauter checkTideSystem dans le même
     *              run : sinon l'écart-type encore bas recoupe immédiatement la pompe.
     */
    public function execute(): bool
    {
        if (!file_exists($this->flagFile)) {
            return false;
        }

        $scheduledTime = (int) file_get_contents($this->flagFile);
        $currentTime = $this->currentTimeOverride ?? time();
        $elapsedTime = $currentTime - $scheduledTime;

        if ($elapsedTime >= self::RESTART_DELAY) {
            $this->logger->info('Délai de redémarrage écoulé. Redémarrage de la pompe aquarium...');
            $this->pumpService->runPompeAqua();
            unlink($this->flagFile);
            $this->logger->info('Pompe aquarium redémarrée avec succès.');
            return true;
        }

        $remainingTime = self::RESTART_DELAY - $elapsedTime;
        $this->logger->info("Redémarrage programmé dans {$remainingTime} secondes.");
        return false;
    }
}
