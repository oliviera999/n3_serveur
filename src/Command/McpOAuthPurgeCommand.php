<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\McpOAuthRepository;
use App\Service\LogService;

/**
 * Sous-commande CRON : purge des codes, jetons, plans de pilotage et documents CIMD
 * expirés du connecteur MCP. Exécutée par {@see CronOrchestrator} dans les tâches
 * horaires (sous le verrou flock de l'orchestrateur).
 *
 * Tolérante : si les tables MCP n'existent pas (migration non jouée), elle journalise
 * et n'interrompt pas le CRON.
 */
class McpOAuthPurgeCommand
{
    public function __construct(
        private McpOAuthRepository $repo,
        private LogService $logger,
    ) {
    }

    /**
     * @return array<string, int>|null null si la purge n'a pas pu s'exécuter
     */
    public function execute(): ?array
    {
        try {
            $result = $this->repo->purgeExpired();
        } catch (\Throwable $e) {
            $this->logger->info('Purge MCP OAuth ignorée (tables absentes ?) : ' . $e->getMessage());

            return null;
        }
        if (array_sum($result) > 0) {
            $this->logger->info('Purge MCP OAuth : ' . (string) json_encode($result));
        }

        return $result;
    }
}
