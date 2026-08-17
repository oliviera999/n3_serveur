<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Service\OperationalSettingsService;

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
 *  - PLUIE    : champ `Pluie` (4095 = sec, plus bas = mouillé, ≤ 3 = sonde
 *               déconnectée) sous `MSP_RAIN_WET_THRESHOLD` (P3, latch,
 *               ré-armement à +5 %). Depuis le firmware msp v2.72, la valeur
 *               est BIMODALE : sortie numérique DO du module projetée sur
 *               l'échelle historique (4095 = sec, 100 = mouillé) — GPIO27 est
 *               sur l'ADC2 de l'ESP32, inutilisable en analogique WiFi actif,
 *               l'ancienne lecture renvoyait toujours la sentinelle. Tout
 *               seuil entre ~200 et 4000 fonctionne ; la logique (dont le
 *               garde ≤ 3, désormais théorique) est inchangée.
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

    public function __construct(
        \App\Repository\AbstractSensorRepository $sensorRepo,
        \App\Service\NotificationService $notifier,
        \App\Service\LogService $logger,
        DerivedAlertStateStore $stateStore,
        private ?OperationalSettingsService $operationalSettings = null,
    ) {
        parent::__construct($sensorRepo, $notifier, $logger, $stateStore);
    }

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

    /** Seuil opt-in : null (désactivé) si absent/vide/non numérique. */
    private function optInThreshold(string $envKey): ?float
    {
        $fromOps = $this->operationalSettings?->optionalFloat($envKey);
        if ($fromOps !== null) {
            return $fromOps;
        }

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

        $this->evaluateLatchedLowValue(
            $state,
            'frost',
            $temp,
            $threshold,
            $threshold + self::TEMP_HYSTERESIS_C,
            self::DIRECTION_LOW,
            function () use ($temp, $threshold): void {
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
            },
            function (): void {
                $this->logger->info('MSP1 : température remontée au-dessus du seuil de gel, alerte ré-armée.');
            },
        );
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

        // Variante seuil HAUT : DIRECTION_HIGH réutilise l'évaluateur « valeur basse »
        // sur les valeurs négées (Raise ⇔ temp > seuil, Clear ⇔ temp < seuil - hyst).
        $this->evaluateLatchedLowValue(
            $state,
            'heat',
            $temp,
            $threshold,
            $threshold - self::TEMP_HYSTERESIS_C,
            self::DIRECTION_HIGH,
            function () use ($temp, $threshold): void {
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
            },
            function (): void {
                $this->logger->info('MSP1 : température redescendue sous le seuil de canicule, alerte ré-armée.');
            },
        );
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

        $this->evaluateLatchedLowValue(
            $state,
            'rain',
            $rain,
            $threshold,
            null,
            self::DIRECTION_LOW,
            function () use ($rain, $threshold): void {
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
            },
            function (): void {
                $this->logger->info('MSP1 : capteur pluie redevenu sec, alerte ré-armée.');
            },
        );
    }
}
