<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Ffp3GpioMap;

/**
 * Service utilitaire pour les GPIO FFP3.
 * Fournit le mapping GPIO ↔ propriétés SensorData utilisé par OutputService et OutputCacheService.
 *
 * Le mapping n'est plus défini ici : il est délégué à {@see Ffp3GpioMap}, source
 * unique de vérité partagée avec OutputRepository et OutputService.
 */
class OutputSyncService
{
    /**
     * Retourne le mapping GPIO → propriété SensorData pour référence.
     *
     * @return array<int, string>
     */
    public static function getGpioMapping(): array
    {
        return Ffp3GpioMap::gpioToProperty();
    }
}
