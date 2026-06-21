<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Config\TableConfig;
use App\Controller\AbstractHeartbeatController;

/**
 * Heartbeat firmware msp (station meteo, board=2).
 *
 * Route prod : POST /msp1/heartbeat
 * Route test : POST /msp1-test/heartbeat
 */
class MspHeartbeatController extends AbstractHeartbeatController
{
    protected function componentName(): string
    {
        return 'MspHeartbeat';
    }

    protected function allowedTables(): array
    {
        return ['msp1Heartbeat', 'msp1HeartbeatTest'];
    }

    protected function targetTable(): string
    {
        return TableConfig::getMspHeartbeatTable();
    }
}
