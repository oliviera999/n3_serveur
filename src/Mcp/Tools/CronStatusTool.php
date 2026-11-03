<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Health\CronStatusReader;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;

/**
 * `iot_cron_status` — dernière exécution de CronOrchestrator et de ses sous-commandes, retards.
 * Rôle administrateur (informations d'exploitation).
 */
final class CronStatusTool extends AbstractTool
{
    public function __construct(McpConfig $config, private CronStatusReader $reader)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_cron_status';
    }

    public function title(): string
    {
        return 'État du CRON';
    }

    public function description(): string
    {
        return 'Administrateur. État du CRON du serveur (crontab chaque minute → run-cron.php → CronOrchestrator) à partir de '
            . 'ce que le code trace déjà : dernier passage de l\'orchestrateur (début, fin, durée, statut), run en cours (verrou '
            . 'flock tenu), dernière exécution et retard de chaque sous-tâche (tâches horaires : appareils silencieux, hors ligne, '
            . 'digest, purge MCP ; normalisation des photos ; rapport de santé), redémarrage de pompe programmé. Un orchestrateur '
            . 'sans passage depuis plus de 5 min = crontab arrêtée ou en erreur : lire alors le journal d\'erreurs PHP (iot_read_file).';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);

        return ToolResult::json($this->reader->status());
    }
}
