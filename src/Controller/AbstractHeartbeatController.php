<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Concerns\LegacyHeartbeatHandler;
use App\Service\LogService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur abstrait pour les heartbeats des firmwares legacy (MSP1, N3PP).
 * Factorise la construction du LegacyHeartbeatHandler et la délégation de handle().
 * Chaque sous-classe fournit le nom de composant, la whitelist de tables et la
 * résolution de la table cible (via TableConfig).
 */
abstract class AbstractHeartbeatController
{
    private LegacyHeartbeatHandler $handler;

    public function __construct(LogService $logger, PDO $pdo)
    {
        $this->handler = new LegacyHeartbeatHandler(
            logger: $logger,
            pdo: $pdo,
            componentName: $this->componentName(),
            allowedTables: $this->allowedTables(),
        );
    }

    /**
     * Nom du composant pour les logs (ex. "MspHeartbeat").
     */
    abstract protected function componentName(): string;

    /**
     * Whitelist stricte des tables heartbeat autorisées pour ce module.
     *
     * @return string[]
     */
    abstract protected function allowedTables(): array;

    /**
     * Résout la table heartbeat cible pour la requête courante (via TableConfig).
     */
    abstract protected function targetTable(): string;

    public function handle(Request $request, Response $response): Response
    {
        return $this->handler->handle($request, $response, $this->targetTable());
    }
}
