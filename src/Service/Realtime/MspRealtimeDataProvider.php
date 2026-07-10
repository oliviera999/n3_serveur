<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Repository\MspOutputRepository;
use App\Repository\MspSensorRepository;

/**
 * Fournisseur de données temps réel pour le module MSP1 (station météo).
 */
class MspRealtimeDataProvider extends AbstractSensorRealtimeDataProvider
{
    private const BOARD = 2;

    public function __construct(
        MspSensorRepository $sensorRepo,
        MspOutputRepository $outputRepo,
    ) {
        parent::__construct($sensorRepo, $outputRepo, self::BOARD);
    }

    protected function getOutputsForBoard(): array
    {
        return $this->outputRepo->getAllForBoard(self::BOARD);
    }
}
