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
     * Calcule la variance d'un tableau de valeurs.
     *
     * NOTE : il s'agit de la variance de POPULATION (division par n, et non n-1).
     * Choix assumé et cohérent avec les statistiques existantes ; ne pas changer la formule.
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
        // Boucle simple avec multiplication ($d*$d) plutôt que array_map + pow.
        $sumSquaredDiffs = 0.0;
        foreach ($values as $x) {
            $d = $x - $mean;
            $sumSquaredDiffs += $d * $d;
        }

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
        return (float) min($values);
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
        return (float) max($values);
    }

    /**
     * Calcule la régression linéaire (moindres carrés) d'un nuage de points.
     *
     * Ajuste la droite y = slope·x + intercept qui minimise l'erreur quadratique.
     *
     * @param array<int|float> $x Abscisses
     * @param array<int|float> $y Ordonnées (même longueur que $x)
     * @return array{slope: float, intercept: float}|null Coefficients, ou null si
     *         moins de 2 points ou si tous les x sont identiques (pente indéfinie)
     */
    public static function linearRegression(array $x, array $y): ?array
    {
        $x = array_values($x);
        $y = array_values($y);
        $count = count($x);
        if ($count < 2 || $count !== count($y)) {
            return null;
        }

        $meanX = array_sum($x) / $count;
        $meanY = array_sum($y) / $count;

        $covariance = 0.0;
        $varianceX = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $dx = (float) $x[$i] - $meanX;
            $covariance += $dx * ((float) $y[$i] - $meanY);
            $varianceX += $dx * $dx;
        }

        if ($varianceX <= 0.0) {
            return null;
        }

        $slope = $covariance / $varianceX;

        return [
            'slope' => $slope,
            'intercept' => $meanY - $slope * $meanX,
        ];
    }

    /**
     * Filtre les valeurs null/vides d'un tableau
     *
     * @param array $values Tableau de valeurs
     * @return array Tableau filtré (valeurs non null et non vides)
     */
    public static function filterValid(array $values): array
    {
        return array_values(array_filter($values, function ($value) {
            return $value !== null && $value !== '';
        }));
    }
}
