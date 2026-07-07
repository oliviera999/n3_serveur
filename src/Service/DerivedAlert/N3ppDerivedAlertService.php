<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

use App\Notification\NotificationCategory;
use App\Notification\Severity;

/**
 * Alertes N3PP dérivées du POST (Phase 2 arbitrage mails) :
 *  - batterie faible + redémarrage (socle {@see AbstractVitalsDerivedAlertService}) ;
 *  - SOL SEC : `HumidMoy < SeuilSec` (les deux au POST), latch + hystérésis de
 *    ré-armement +5 % (parité `seuilRetourNormal()` du firmware n3pp), avec mail
 *    de retour à la normale (comme le firmware).
 *
 * Garde de validité sol : le firmware bloque l'alerte quand aucun capteur sol n'est
 * valide (capteurs débranchés lus « très sec ») ; côté serveur on exige qu'au moins
 * une des sondes Humid1..4 soit > 0.
 */
class N3ppDerivedAlertService extends AbstractVitalsDerivedAlertService
{
    protected function family(): string
    {
        return 'N3PP';
    }

    protected function throttleKeyPrefix(): string
    {
        return 'n3pp';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     */
    protected function checkFamilySpecific(array $row, array &$state): void
    {
        $humidMoy = $this->toFloatOrNull($row['HumidMoy'] ?? null);
        $seuilSec = $this->toFloatOrNull($row['SeuilSec'] ?? null);
        if ($humidMoy === null || $seuilSec === null || $seuilSec <= 0) {
            return;
        }

        if (!$this->hasValidSoilSensor($row)) {
            return;
        }

        $alreadyDry = (bool) ($state['soilDry'] ?? false);
        $decision = LowValueAlertEvaluator::evaluate($humidMoy, $seuilSec, $alreadyDry);

        if ($decision === LowValueDecision::Raise) {
            $message = sprintf(
                "Le sol est sec : humidité moyenne %.0f (seuil %.0f).\n"
                . "L'arrosage automatique du firmware peut se déclencher (cooldown 5 min).",
                $humidMoy,
                $seuilSec
            );
            $this->notifier->sendAlert(
                Severity::Alert,
                NotificationCategory::Environment,
                $this->family(),
                'Sol sec',
                $message,
                'n3pp:soil-dry'
            );
            $state['soilDry'] = true;
        } elseif ($decision === LowValueDecision::Clear) {
            $message = sprintf(
                "L'humidité du sol est remontée : moyenne %.0f (seuil %.0f + hystérésis).",
                $humidMoy,
                $seuilSec
            );
            $this->notifier->sendAlert(
                Severity::Info,
                NotificationCategory::Environment,
                $this->family(),
                'Humidité du sol redevenue normale',
                $message,
                'n3pp:soil-ok'
            );
            $state['soilDry'] = false;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasValidSoilSensor(array $row): bool
    {
        foreach (['Humid1', 'Humid2', 'Humid3', 'Humid4'] as $column) {
            $value = $this->toFloatOrNull($row[$column] ?? null);
            if ($value !== null && $value > 0) {
                return true;
            }
        }

        return false;
    }
}
