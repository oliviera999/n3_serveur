<?php

declare(strict_types=1);

namespace App\Controller\Energie;

use App\Controller\AbstractRealtimeApiController;
use App\Service\Realtime\EnergieRealtimeDataProvider;

/**
 * Contrôleur API temps réel du banc énergie (`/energie[-test]/api/realtime/*`).
 * Famille « mesure seule » : outputs/state et alerts/active renvoient des listes vides.
 */
class EnergieRealtimeApiController extends AbstractRealtimeApiController
{
    public function __construct(EnergieRealtimeDataProvider $provider)
    {
        parent::__construct($provider);
    }
}
