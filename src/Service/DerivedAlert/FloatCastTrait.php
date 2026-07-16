<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

/**
 * Conversion numérique tolérante partagée par les services d'alertes dérivées.
 *
 * Extrait pour supprimer la duplication mot-pour-mot entre
 * {@see AbstractVitalsDerivedAlertService} (socle N3PP/MSP1) et
 * {@see Ffp3DerivedAlertService} — ce dernier n'hérite PAS du socle (DI/tests
 * distincts), donc le trait est le point de mutualisation le moins risqué.
 */
trait FloatCastTrait
{
    protected function toFloatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
