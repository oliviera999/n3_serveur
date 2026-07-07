<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

/**
 * Alertes MSP1 dérivées du POST (Phase 2 arbitrage mails) : batterie faible et
 * redémarrage — voir {@see AbstractVitalsDerivedAlertService}. La station météo
 * n'a pas d'autre alerte partagée (le rapport réseau P4 reste un diagnostic
 * intrinsèque à l'ESP, cf. ARCHITECTURE_MAILS_ARBITRAGE.md §3.2).
 */
class MspDerivedAlertService extends AbstractVitalsDerivedAlertService
{
    protected function family(): string
    {
        return 'MSP1';
    }

    protected function throttleKeyPrefix(): string
    {
        return 'msp1';
    }
}
