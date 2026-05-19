<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Config\TableConfig;
use App\Controller\Concerns\LegacyHeartbeatHandler;
use App\Service\LogService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Heartbeat firmware msp (station meteo, board=2).
 *
 * Route prod : POST /msp1/heartbeat
 * Route test : POST /msp1-test/heartbeat
 */
class MspHeartbeatController
{
    private const ALLOWED_TABLES = ['msp1Heartbeat', 'msp1HeartbeatTest'];

    private LegacyHeartbeatHandler $handler;

    public function __construct(LogService $logger, PDO $pdo)
    {
        $this->handler = new LegacyHeartbeatHandler(
            logger: $logger,
            pdo: $pdo,
            componentName: 'MspHeartbeat',
            allowedTables: self::ALLOWED_TABLES,
        );
    }

    public function handle(Request $request, Response $response): Response
    {
        return $this->handler->handle($request, $response, TableConfig::getMspHeartbeatTable());
    }
}
