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
     * - 108, 109, 110 : Commandes nourrissage
     * - 115 : Forçage réveil (checkbox)
     */
    private const BOOLEAN_GPIOS = [101, 108, 109, 110, 115];

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
        if (!self::isBooleanGpio($gpio)) {
            // GPIOs non-booléens : retourner tel quel (email, paramètres)
            return $state;
        }

        // GPIOs booléens : convertir en entier 0 ou 1
        if (is_string($state)) {
            return match (strtolower(trim($state))) {
                'checked', 'true', 'on', '1', 'yes' => 1,
                'unchecked', 'false', 'off', '0', 'no' => 0,
                default => is_numeric($state) ? (int)$state : 0
            };
        }

        return (int)$state;
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
            $gpio = (int)($result['gpio'] ?? -1);
            if (isset($result['state'])) {
                $result['state'] = self::normalize($gpio, $result['state']);
            }
        }
        return $results;
    }
}
