<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Controller\AbstractRealtimeApiController;
use App\Service\Realtime\MspRealtimeDataProvider;

/**
 * Contrôleur API temps réel MSP1 (station météo).
 * Délègue à MspRealtimeDataProvider via le contrat commun.
 */
class MspRealtimeApiController extends AbstractRealtimeApiController
{
    public function __construct(
        MspRealtimeDataProvider $provider
    ) {
        parent::__construct($provider);
    }
}
