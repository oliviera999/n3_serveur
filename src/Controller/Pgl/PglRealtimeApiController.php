<?php

declare(strict_types=1);

namespace App\Controller\Pgl;

use App\Controller\AbstractRealtimeApiController;
use App\Service\Realtime\PglRealtimeDataProvider;

/**
 * Contrôleur API temps réel Poissonglouton.
 */
class PglRealtimeApiController extends AbstractRealtimeApiController
{
    public function __construct(PglRealtimeDataProvider $provider)
    {
        parent::__construct($provider);
    }
}
