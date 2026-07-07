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
 *    de retour à la normale (comme le firmware) ;
 *  - ARROSAGE EFFECTUÉ : transition `etatPompe` 0→1 (le firmware POste pendant
 *    l'arrosage) — reprend les confirmations P3 du firmware ;
 *  - POMPE CONTINUE : `etatPompe = 1` sur plusieurs lignes consécutives = pompe
 *    maintenue ON (P1, reprend l'alerte « arrosage continu » du firmware).
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
        $this->checkSoil($row, $state);
        $this->checkWateringPump($row, $state);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     */
    private function checkSoil(array $row, array &$state): void
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

    /** Nombre de lignes consécutives avec pompe ON avant l'alerte « arrosage continu ». */
    private const PUMP_STUCK_MIN_STREAK = 2;

    /**
     * Arrosage effectué (transition 0→1 de `etatPompe`) + pompe continue
     * (`etatPompe = 1` sur ≥ PUMP_STUCK_MIN_STREAK lignes consécutives : la pompe
     * est restée ON à travers un cycle de réveil complet, comme le latch
     * `emailPompeSent` du firmware).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     */
    private function checkWateringPump(array $row, array &$state): void
    {
        $raw = $row['etatPompe'] ?? null;
        if ($raw === null || !is_numeric($raw)) {
            return;
        }
        $running = ((int) $raw) === 1;

        $previous = $state['pump']['lastState'] ?? null;
        $streak = (int) ($state['pump']['onStreak'] ?? 0);
        $stuckAlerted = (bool) ($state['pump']['stuckAlerted'] ?? false);

        $streak = $running ? $streak + 1 : 0;
        $state['pump']['lastState'] = $running;
        $state['pump']['onStreak'] = $streak;

        if (!$running) {
            if ($stuckAlerted) {
                $this->logger->info('N3PP : pompe relâchée, alerte « arrosage continu » ré-armée.');
            }
            $state['pump']['stuckAlerted'] = false;

            return;
        }

        // Confirmation d'arrosage : transition OFF→ON (premier passage silencieux).
        if ($previous === false) {
            $humidMoy = $this->toFloatOrNull($row['HumidMoy'] ?? null);
            $message = sprintf(
                "La pompe d'arrosage vient d'être activée (humidité moyenne du sol : %s).",
                $humidMoy !== null ? sprintf('%.0f', $humidMoy) : 'inconnue'
            );
            // Pas de clé d'anti-spam : dédup par transition (plusieurs arrosages
            // légitimes par jour — un cooldown P3 6 h les avalerait).
            $this->notifier->sendAlert(
                Severity::Info,
                NotificationCategory::Hydraulic,
                $this->family(),
                'Arrosage effectué',
                $message
            );
        }

        // Pompe maintenue ON à travers les réveils : anomalie (arrosage continu).
        if ($streak >= self::PUMP_STUCK_MIN_STREAK && !$stuckAlerted) {
            $message = sprintf(
                "ATTENTION : la pompe d'arrosage est restée active sur %d cycles de réveil consécutifs.\n"
                . 'Arrosage continu probable (commande serveur bloquée, relais collé ou firmware figé).',
                $streak
            );
            $sent = $this->notifier->sendAlert(
                Severity::Critical,
                NotificationCategory::Hydraulic,
                $this->family(),
                'Arrosage continu en cours',
                $message,
                'n3pp:pump-stuck'
            );
            if ($sent) {
                $state['pump']['stuckAlerted'] = true;
            }
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
