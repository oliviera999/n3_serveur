<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Controller\AbstractRealtimeApiController;
use App\Service\FirmwareStateCompat;
use App\Service\OperationalSettingsService;
use App\Service\Realtime\N3ppRealtimeDataProvider;

/**
 * Contrôleur API temps réel N3PP (serre / élevage).
 * Délègue à N3ppRealtimeDataProvider via la classe abstraite commune.
 */
class N3ppRealtimeApiController extends AbstractRealtimeApiController
{
    public function __construct(
        N3ppRealtimeDataProvider $provider,
        ?OperationalSettingsService $operationalSettings = null,
    ) {
        parent::__construct($provider, $operationalSettings);
    }

    protected function firmwareModule(): string
    {
        return FirmwareStateCompat::MODULE_N3PP;
    }
}
