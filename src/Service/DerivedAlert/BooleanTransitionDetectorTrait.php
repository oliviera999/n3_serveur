<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

/**
 * Détecteur de transition booléenne « latché sur envoi ».
 *
 * Squelette commun aux transitions FFP3 (chauffage ON/OFF, remplissage réserve) :
 *  - cast de la valeur brute en bool (null / non numérique → pas d'évaluation) ;
 *  - 1re passe silencieuse : initialise `lastState` sans notifier (pas de
 *    transition observable) ;
 *  - notification uniquement au flip, via le callback fourni ;
 *  - n'avance `lastState` QUE si l'envoi a réussi (parité `checkFlood` / Phase 0
 *    firmware, fix Wave 1) : sinon la transition est réévaluée — et le mail
 *    retenté — au tick suivant.
 *
 * NB : volontairement PAS utilisé par `N3ppDerivedAlertService::checkWateringPump`,
 * dont la sémantique diffère (avancée inconditionnelle de `lastState`, imbriquée
 * avec le comptage de streak « pompe bloquée ») ; l'y plaquer changerait le
 * comportement. Voir le commentaire sur cette méthode.
 */
trait BooleanTransitionDetectorTrait
{
    /**
     * @param array<string, mixed> $state    État persistant (modifié par référence)
     * @param string               $stateKey Sous-clé d'état (ex. 'heater', 'tankPump')
     * @param mixed                $raw      Valeur brute (0/1) issue de la ligne
     * @param callable(bool): bool $notify   Reçoit le nouvel état booléen, envoie le
     *                                       mail et retourne true si l'envoi a réussi
     */
    protected function detectTransition(array &$state, string $stateKey, mixed $raw, callable $notify): void
    {
        if ($raw === null || !is_numeric($raw)) {
            return;
        }
        $running = ((int) $raw) === 1;

        $previous = $state[$stateKey]['lastState'] ?? null;

        // Premier passage : initialiser sans notifier (pas de transition observable).
        if (!is_bool($previous)) {
            $state[$stateKey]['lastState'] = $running;

            return;
        }
        if ($previous === $running) {
            return;
        }

        $sent = $notify($running);

        // N'avancer l'état latché QUE si l'envoi a réussi (parité avec checkFlood) :
        // sinon la transition sera réévaluée — et le mail retenté — au prochain tick.
        if ($sent) {
            $state[$stateKey]['lastState'] = $running;
        }
    }
}
