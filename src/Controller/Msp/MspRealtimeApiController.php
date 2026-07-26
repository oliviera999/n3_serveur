<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Controller\AbstractRealtimeApiController;
use App\Service\FirmwareStateCompat;
use App\Service\OperationalSettingsService;
use App\Service\Realtime\MspRealtimeDataProvider;

/**
 * Contrôleur API temps réel MSP1 (station météo).
 * Délègue à MspRealtimeDataProvider via la classe abstraite commune.
 */
class MspRealtimeApiController extends AbstractRealtimeApiController
{
    public function __construct(
        MspRealtimeDataProvider $provider,
        ?OperationalSettingsService $operationalSettings = null,
    ) {
        parent::__construct($provider, $operationalSettings);
    }

    protected function firmwareModule(): string
    {
        return FirmwareStateCompat::MODULE_MSP1;
    }
}
