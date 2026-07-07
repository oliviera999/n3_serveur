<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

/**
 * Évaluateur pur « valeur sous le seuil » avec latch + hystérésis de ré-armement.
 *
 * Porte côté serveur l'anti-spam à état des firmwares n3pp/msp (Phase 2 arbitrage
 * mails) : une alerte est levée UNE fois au passage sous le seuil, puis ré-armée
 * seulement quand la valeur repasse au-dessus de `seuil + hystérésis` (défaut +5 %,
 * parité avec `seuilRetourNormal()` du firmware n3pp : `seuil + seuil/20`).
 *
 * Utilisé pour : batterie faible (PontDiv vs SeuilPontDiv, n3pp + msp) et sol sec
 * (HumidMoy vs SeuilSec, n3pp). Pur (aucune E/S) donc testable unitairement.
 */
final class LowValueAlertEvaluator
{
    public static function evaluate(
        float $value,
        float $threshold,
        bool $alreadyLow,
        ?float $clearThreshold = null
    ): LowValueDecision {
        $clearThreshold ??= $threshold + ($threshold / 20.0);

        if (!$alreadyLow && $value < $threshold) {
            return LowValueDecision::Raise;
        }

        if ($alreadyLow && $value > $clearThreshold) {
            return LowValueDecision::Clear;
        }

        return LowValueDecision::None;
    }
}
