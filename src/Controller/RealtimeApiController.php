<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\RealtimeDataService;

/**
 * Contrôleur API temps réel FFP3 (aquaponie).
 * Délègue à RealtimeDataService qui implémente RealtimeDataProviderInterface.
 */
class RealtimeApiController extends AbstractRealtimeApiController
{
    public function __construct(
        RealtimeDataService $realtimeService
    ) {
        parent::__construct($realtimeService);
    }
}
