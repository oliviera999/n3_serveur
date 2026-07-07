<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

/**
 * Machine d'état anti-spam « aquarium trop plein » — port serveur de la machine
 * pure `FloodAlert` du firmware ffp5cs (Phase 2 arbitrage mails).
 *
 * Sémantique identique au firmware (`ffp5cs/include/automatism/flood_alert.h`) :
 *  - « trop plein » = distance capteur→surface FAIBLE (EauAquarium < limFlood) ;
 *  - debounce : temps minimal passé sous le seuil avant d'alerter ;
 *  - cooldown : délai minimal entre deux e-mails ;
 *  - sortie : niveau au-dessus de `limFlood + hystérésis`, stable un temps donné.
 *
 * Pure (aucune E/S) : l'état est un tableau sérialisable (persisté par le service
 * appelant via {@see DerivedAlertStateStore}), les décisions sont retournées à
 * l'appelant qui exécute les effets de bord (envoi mail via NotificationService).
 */
final class FloodStateMachine
{
    public const DECISION_NONE = 'none';
    public const DECISION_SEND_EMAIL = 'send_email';
    public const DECISION_EXIT_FLOOD = 'exit_flood';

    /**
     * État initial (aucun trop-plein connu).
     *
     * @return array{inFlood: bool, enterSinceTs: int, aboveResetSinceTs: int, lastEmailTs: int}
     */
    public static function initialState(): array
    {
        return [
            'inFlood' => false,
            'enterSinceTs' => 0,
            'aboveResetSinceTs' => 0,
            'lastEmailTs' => 0,
        ];
    }

    /**
     * Met à jour les horodatages de `$state` selon le niveau courant et retourne la
     * décision (parité stricte avec `FloodAlert::evaluate` du firmware).
     *
     * @param array{inFlood: bool, enterSinceTs: int, aboveResetSinceTs: int, lastEmailTs: int} $state
     * @param float $levelMm          Distance capteur→surface (mm)
     * @param int   $nowTs            Epoch courant (s)
     * @param float $limFloodMm       Sous cette distance = trop plein (mm)
     * @param float $resetThresholdMm Seuil d'hystérésis de sortie (mm)
     * @param int   $debounceSec      Temps min sous le seuil avant alerte
     * @param int   $cooldownSec      Délai min entre deux e-mails
     * @param int   $resetStableSec   Temps stable au-dessus pour sortir
     *
     * @return string L'une des constantes DECISION_*
     */
    public static function evaluate(
        array &$state,
        float $levelMm,
        int $nowTs,
        float $limFloodMm,
        float $resetThresholdMm,
        int $debounceSec,
        int $cooldownSec,
        int $resetStableSec
    ): string {
        if ($levelMm < $limFloodMm) {
            if ($state['enterSinceTs'] === 0) {
                $state['enterSinceTs'] = $nowTs;
            }
            $state['aboveResetSinceTs'] = 0;

            if (!$state['inFlood']) {
                $elapsedUnder = max(0, $nowTs - $state['enterSinceTs']);
                $debounceOk = $elapsedUnder >= $debounceSec;
                $cooldownOk = $state['lastEmailTs'] === 0
                    || ($nowTs - $state['lastEmailTs']) >= $cooldownSec;
                if ($debounceOk && $cooldownOk) {
                    return self::DECISION_SEND_EMAIL;
                }
            }

            return self::DECISION_NONE;
        }

        // Niveau au-dessus de limFlood : candidat à la sortie de l'état trop-plein.
        $state['enterSinceTs'] = 0;
        if ($levelMm >= $resetThresholdMm) {
            if ($state['aboveResetSinceTs'] === 0) {
                $state['aboveResetSinceTs'] = $nowTs;
            }
            $elapsedAbove = max(0, $nowTs - $state['aboveResetSinceTs']);
            if ($elapsedAbove >= $resetStableSec) {
                $state['inFlood'] = false;

                return self::DECISION_EXIT_FLOOD;
            }
        } else {
            $state['aboveResetSinceTs'] = 0;
        }

        return self::DECISION_NONE;
    }

    /**
     * À appeler après un envoi d'e-mail RÉUSSI : arme l'anti-spam (entre en flood).
     * Ne pas appeler sur un envoi filtré/échoué — l'alerte sera retentée (parité
     * avec le latching « livraison confirmée » de la Phase 0 firmware).
     *
     * @param array{inFlood: bool, enterSinceTs: int, aboveResetSinceTs: int, lastEmailTs: int} $state
     */
    public static function markEmailSent(array &$state, int $nowTs): void
    {
        $state['inFlood'] = true;
        $state['lastEmailTs'] = $nowTs;
    }
}
