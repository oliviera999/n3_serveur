<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Repository\MspSensorRepository;
use App\Repository\MspOutputRepository;

/**
 * Fournisseur de données temps réel pour le module MSP1 (station météo).
 */
class MspRealtimeDataProvider extends AbstractSensorRealtimeDataProvider
{
    private const BOARD = 2;

    public function __construct(
        MspSensorRepository $sensorRepo,
        private MspOutputRepository $outputRepo,
    ) {
        parent::__construct($sensorRepo, self::BOARD);
    }

    protected function getOutputsForBoard(): array
    {
        return $this->outputRepo->getAllForBoard(self::BOARD);
    }
}
