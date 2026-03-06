<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Utilitaires mathématiques et statistiques.
 * 
 * Centralise les calculs statistiques utilisés dans plusieurs services.
 */
class MathUtils
{
    /**
     * Calcule la moyenne d'un tableau de valeurs
     *
     * @param array<int|float> $values Tableau de valeurs numériques
     * @return float|null Moyenne ou null si tableau vide
     */
    public static function mean(array $values): ?float
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }
        return array_sum($values) / $count;
    }

    /**
     * Calcule la variance d'un tableau de valeurs
     *
     * @param array<int|float> $values Tableau de valeurs numériques
     * @return float|null Variance ou null si moins de 2 valeurs
     */
    public static function variance(array $values): ?float
    {
        $count = count($values);
        if ($count < 2) {
            return null;
        }

        $mean = array_sum($values) / $count;
        $sumSquaredDiffs = array_sum(array_map(
            fn($x) => pow($x - $mean, 2),
            $values
        ));

        return $sumSquaredDiffs / $count;
    }

    /**
     * Calcule l'écart-type d'un tableau de valeurs
     *
     * @param array<int|float> $values Tableau de valeurs numériques
     * @return float|null Écart-type ou null si moins de 2 valeurs
     */
    public static function standardDeviation(array $values): ?float
    {
        $variance = self::variance($values);
        if ($variance === null) {
            return null;
        }
        return sqrt($variance);
    }

    /**
     * Calcule le minimum d'un tableau, avec gestion des tableaux vides
     *
     * @param array<int|float> $values Tableau de valeurs numériques
     * @return float|null Minimum ou null si tableau vide
     */
    public static function min(array $values): ?float
    {
        if (count($values) === 0) {
            return null;
        }
        return (float)min($values);
    }

    /**
     * Calcule le maximum d'un tableau, avec gestion des tableaux vides
     *
     * @param array<int|float> $values Tableau de valeurs numériques
     * @return float|null Maximum ou null si tableau vide
     */
    public static function max(array $values): ?float
    {
        if (count($values) === 0) {
            return null;
        }
        return (float)max($values);
    }

    /**
     * Filtre les valeurs null/vides d'un tableau
     *
     * @param array $values Tableau de valeurs
     * @return array Tableau filtré (valeurs non null et non vides)
     */
    public static function filterValid(array $values): array
    {
        return array_values(array_filter($values, function($value) {
            return $value !== null && $value !== '';
        }));
    }
}
