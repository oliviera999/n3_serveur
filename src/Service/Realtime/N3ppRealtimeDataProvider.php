<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Repository\N3ppOutputRepository;
use App\Repository\N3ppSensorRepository;

/**
 * Fournisseur de données temps réel pour le module N3PP (serre / élevage).
 */
class N3ppRealtimeDataProvider extends AbstractSensorRealtimeDataProvider
{
    private const BOARD = 3;

    public function __construct(
        N3ppSensorRepository $sensorRepo,
        N3ppOutputRepository $outputRepo,
    ) {
        parent::__construct($sensorRepo, $outputRepo, self::BOARD);
    }

    protected function getOutputsForBoard(): array
    {
        return $this->outputRepo->getAllForBoard(self::BOARD);
    }
}
