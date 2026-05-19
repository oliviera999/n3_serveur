<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Config\TableConfig;
use App\Controller\Concerns\LegacyHeartbeatHandler;
use App\Service\LogService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Heartbeat firmware n3pp (serre / aquaponie, board=3).
 *
 * Route prod : POST /n3pp/heartbeat
 * Route test : POST /n3pp-test/heartbeat
 */
class N3ppHeartbeatController
{
    private const ALLOWED_TABLES = ['n3ppHeartbeat', 'n3ppHeartbeatTest'];

    private LegacyHeartbeatHandler $handler;

    public function __construct(LogService $logger, PDO $pdo)
    {
        $this->handler = new LegacyHeartbeatHandler(
            logger: $logger,
            pdo: $pdo,
            componentName: 'N3ppHeartbeat',
            allowedTables: self::ALLOWED_TABLES,
        );
    }

    public function handle(Request $request, Response $response): Response
    {
        return $this->handler->handle($request, $response, TableConfig::getN3ppHeartbeatTable());
    }
}
