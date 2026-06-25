<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Utilitaire de normalisation des états GPIO
 *
 * Centralise la logique de conversion des valeurs d'état
 * pour éviter la duplication entre OutputRepository et OutputCacheService
 */
class StateNormalizer
{
    /**
     * Liste des GPIOs qui doivent être traités comme des booléens (0/1)
     * - GPIOs < 100 : actionneurs physiques (chauffage, lumière, pompes)
     * - 101 : Notifications (checkbox)
     * - 110 : Commande reset (one-shot booléen)
     * - 115 : Forçage réveil (checkbox)
     *
     * NB : GPIO 108/109 (nourrissage petits/gros) ne sont PLUS booléens depuis le
     * contrat « compteur monotone » (serveur 6.0.0 / firmware 15.0) : leur `state`
     * est un ENTIER croissant = nombre total de repas demandés sur le canal. Le
     * firmware rattrape l'écart avec son propre compteur exécuté (NVS) ; il ne faut
     * donc surtout pas réduire la valeur à 0/1. Voir docs/SYNCHRONISATION_BIDIRECTIONNELLE.md.
     *
     * NB : GPIO 117 (forçage pompe aquarium) n'est PAS booléen : c'est un tri-état
     * 0=auto / 1=forcer ON / 2=forcer OFF (voir {@see normalize()}).
     */
    private const BOOLEAN_GPIOS = [101, 110, 115];

    /** GPIO 117 : sélecteur de mode forçage pompe aquarium (0=auto, 1=ON, 2=OFF). */
    private const AQUARIUM_PUMP_FORCE_GPIO = 117;

    /**
     * Vérifie si un GPIO doit être traité comme un booléen
     *
     * @param int $gpio Numéro du GPIO
     * @return bool True si le GPIO est booléen
     */
    public static function isBooleanGpio(int $gpio): bool
    {
        return $gpio < 100 || in_array($gpio, self::BOOLEAN_GPIOS, true);
    }

    /**
     * Normalise une valeur d'état pour un GPIO donné
     *
     * Pour les GPIOs booléens :
     * - Convertit les strings ('checked', 'true', 'on', '1', 'yes') en 1
     * - Convertit les autres valeurs en 0
     *
     * Pour les autres GPIOs :
     * - Retourne la valeur telle quelle (email, paramètres numériques)
     *
     * @param int $gpio Numéro du GPIO
     * @param mixed $state Valeur brute de l'état
     * @return mixed Valeur normalisée
     */
    public static function normalize(int $gpio, mixed $state): mixed
    {
        // GPIO 117 : tri-état 0=auto, 1=forcer ON, 2=forcer OFF (toute autre valeur => auto).
        if ($gpio === self::AQUARIUM_PUMP_FORCE_GPIO) {
            $mode = is_numeric($state) ? (int) $state : 0;
            return in_array($mode, [1, 2], true) ? $mode : 0;
        }

        if (!self::isBooleanGpio($gpio)) {
            // GPIOs non-booléens : retourner tel quel (email, paramètres)
            return $state;
        }

        // GPIOs booléens : convertir en entier 0 ou 1
        if (is_string($state)) {
            return match (strtolower(trim($state))) {
                'checked', 'true', 'on', '1', 'yes' => 1,
                'unchecked', 'false', 'off', '0', 'no' => 0,
                default => is_numeric($state) ? (int) $state : 0
            };
        }

        return (int) $state;
    }

    /**
     * Normalise un tableau de résultats (typiquement depuis une requête BDD)
     *
     * @param array $results Tableau de résultats avec 'gpio' et 'state'
     * @return array Tableau avec les états normalisés
     */
    public static function normalizeResults(array $results): array
    {
        foreach ($results as &$result) {
            $gpio = (int) ($result['gpio'] ?? -1);
            if (isset($result['state'])) {
                $result['state'] = self::normalize($gpio, $result['state']);
            }
        }
        return $results;
    }
}
