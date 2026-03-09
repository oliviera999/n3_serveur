<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Realtime\Ffp3RealtimeDataProvider;

/**
 * Contrôleur API temps réel FFP3 (aquaponie).
 * Délègue à Ffp3RealtimeDataProvider qui implémente RealtimeDataProviderInterface.
 */
class RealtimeApiController extends AbstractRealtimeApiController
{
    public function __construct(
        Ffp3RealtimeDataProvider $realtimeService
    ) {
        parent::__construct($realtimeService);
    }
}
