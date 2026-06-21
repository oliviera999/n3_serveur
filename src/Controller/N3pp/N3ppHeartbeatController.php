<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Config\TableConfig;
use App\Controller\AbstractHeartbeatController;

/**
 * Heartbeat firmware n3pp (serre / aquaponie, board=3).
 *
 * Route prod : POST /n3pp/heartbeat
 * Route test : POST /n3pp-test/heartbeat
 */
class N3ppHeartbeatController extends AbstractHeartbeatController
{
    protected function componentName(): string
    {
        return 'N3ppHeartbeat';
    }

    protected function allowedTables(): array
    {
        return ['n3ppHeartbeat', 'n3ppHeartbeatTest'];
    }

    protected function targetTable(): string
    {
        return TableConfig::getN3ppHeartbeatTable();
    }
}
