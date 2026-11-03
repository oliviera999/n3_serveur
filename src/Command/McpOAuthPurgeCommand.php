<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\McpConfirmRepository;
use App\Repository\McpOAuthRepository;
use App\Service\LogService;

/**
 * Sous-commande CRON : purge des codes, jetons, plans de pilotage, documents CIMD
 * et confirmations / déverrouillages expirés du connecteur MCP.
 */
class McpOAuthPurgeCommand
{
    public function __construct(
        private McpOAuthRepository $repo,
        private LogService $logger,
        private ?McpConfirmRepository $confirmRepo = null,
    ) {
    }

    /**
     * @return array<string, int>|null null si la purge n'a pas pu s'exécuter
     */
    public function execute(): ?array
    {
        try {
            $result = $this->repo->purgeExpired();
            if ($this->confirmRepo !== null) {
                $result = array_merge($result, $this->confirmRepo->purgeExpired());
            }
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
