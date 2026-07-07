<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

use App\Notification\NotificationCategory;
use App\Notification\Severity;

/**
 * Alertes MSP1 dérivées du POST : batterie faible et redémarrage (socle
 * {@see AbstractVitalsDerivedAlertService}, Phase 2 arbitrage mails), plus des
 * ALERTES MÉTÉO serveur-only (aucun mail firmware équivalent — création pure,
 * cf. note §2.1 du plan : tous les champs météo sont au POST) :
 *
 *  - GEL      : `TempAirExt < MSP_FROST_ALERT_THRESHOLD_C` (P2, latch,
 *               ré-armement silencieux à seuil +2 °C) ;
 *  - CANICULE : `TempAirExt > MSP_HEAT_ALERT_THRESHOLD_C` (P2, latch,
 *               ré-armement silencieux à seuil -2 °C) ;
 *  - PLUIE    : capteur analogique `Pluie` (4095 = sec, plus bas = mouillé,
 *               ≤ 3 = sonde déconnectée) sous `MSP_RAIN_WET_THRESHOLD` (P3,
 *               latch, ré-armement à +5 %).
 *
 * OPT-IN : chaque alerte est désactivée tant que sa variable `.env` n'est pas
 * définie (même approche que RESERVE_LOW_LEVEL_THRESHOLD). Garde-fou capteur :
 * le firmware poste TempAirExt = 20.0 en repli DHT invalide — valeur neutre
 * pour des seuils gel/canicule raisonnables.
 * Le rapport réseau P4 reste un diagnostic intrinsèque à l'ESP (§3.2).
 */
class MspDerivedAlertService extends AbstractVitalsDerivedAlertService
{
    /** Hystérésis de ré-armement des alertes de température (°C). */
    private const TEMP_HYSTERESIS_C = 2.0;
    /** Valeur sentinelle « sonde pluie déconnectée » (lecture brute ≤ 3 côté firmware). */
    private const RAIN_DISCONNECT_MAX = 3.0;

    protected function family(): string
    {
        return 'MSP1';
    }

    protected function throttleKeyPrefix(): string
    {
        return 'msp1';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     */
    protected function checkFamilySpecific(array $row, array &$state): void
    {
        $this->checkFrost($row, $state);
        $this->checkHeat($row, $state);
        $this->checkRain($row, $state);
    }

    /** Seuil `.env` opt-in : null (désactivé) si absent/vide/non numérique. */
    private function optInThreshold(string $envKey): ?float
    {
        if (!isset($_ENV[$envKey]) || (string) $_ENV[$envKey] === '' || !is_numeric($_ENV[$envKey])) {
            return null;
        }

        return (float) $_ENV[$envKey];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     */
    private function checkFrost(array $row, array &$state): void
    {
        $threshold = $this->optInThreshold('MSP_FROST_ALERT_THRESHOLD_C');
        $temp = $this->toFloatOrNull($row['TempAirExt'] ?? null);
        if ($threshold === null || $temp === null) {
            return;
        }

        $alreadyCold = (bool) ($state['frost'] ?? false);
        $decision = LowValueAlertEvaluator::evaluate(
            $temp,
            $threshold,
            $alreadyCold,
            $threshold + self::TEMP_HYSTERESIS_C
        );

        if ($decision === LowValueDecision::Raise) {
            $message = sprintf(
                'Risque de gel : température extérieure %.1f °C (seuil %.1f °C).',
                $temp,
                $threshold
            );
            $this->notifier->sendAlert(
                Severity::Alert,
                NotificationCategory::Environment,
                $this->family(),
                'Risque de gel',
                $message,
                'msp1:frost'
            );
            $state['frost'] = true;
        } elseif ($decision === LowValueDecision::Clear) {
            $this->logger->info('MSP1 : température remontée au-dessus du seuil de gel, alerte ré-armée.');
            $state['frost'] = false;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     */
    private function checkHeat(array $row, array &$state): void
    {
        $threshold = $this->optInThreshold('MSP_HEAT_ALERT_THRESHOLD_C');
        $temp = $this->toFloatOrNull($row['TempAirExt'] ?? null);
        if ($threshold === null || $temp === null) {
            return;
        }

        $alreadyHot = (bool) ($state['heat'] ?? false);

        if (!$alreadyHot && $temp > $threshold) {
            $message = sprintf(
                'Canicule : température extérieure %.1f °C (seuil %.1f °C).',
                $temp,
                $threshold
            );
            $this->notifier->sendAlert(
                Severity::Alert,
                NotificationCategory::Environment,
                $this->family(),
                'Température extérieure élevée',
                $message,
                'msp1:heat'
            );
            $state['heat'] = true;
        } elseif ($alreadyHot && $temp < $threshold - self::TEMP_HYSTERESIS_C) {
            $this->logger->info('MSP1 : température redescendue sous le seuil de canicule, alerte ré-armée.');
            $state['heat'] = false;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     */
    private function checkRain(array $row, array &$state): void
    {
        $threshold = $this->optInThreshold('MSP_RAIN_WET_THRESHOLD');
        $rain = $this->toFloatOrNull($row['Pluie'] ?? null);
        if ($threshold === null || $rain === null) {
            return;
        }
        if ($rain <= self::RAIN_DISCONNECT_MAX) {
            return; // sonde déconnectée (sentinelle firmware), pas une pluie
        }

        $alreadyWet = (bool) ($state['rain'] ?? false);
        $decision = LowValueAlertEvaluator::evaluate($rain, $threshold, $alreadyWet);

        if ($decision === LowValueDecision::Raise) {
            $message = sprintf(
                'Pluie détectée par la station météo (capteur %.0f, seuil %.0f — plus bas = plus mouillé).',
                $rain,
                $threshold
            );
            $this->notifier->sendAlert(
                Severity::Info,
                NotificationCategory::Environment,
                $this->family(),
                'Pluie détectée',
                $message,
                'msp1:rain'
            );
            $state['rain'] = true;
        } elseif ($decision === LowValueDecision::Clear) {
            $this->logger->info('MSP1 : capteur pluie redevenu sec, alerte ré-armée.');
            $state['rain'] = false;
        }
    }
}
