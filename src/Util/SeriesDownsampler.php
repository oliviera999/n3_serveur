<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Sous-échantillonnage (downsampling) de séries temporelles longues.
 *
 * Implémente LTTB (« Largest-Triangle-Three-Buckets »), un algorithme de
 * décimation qui préserve la forme visuelle d'une courbe tout en réduisant
 * drastiquement le nombre de points. À défaut d'axe Y exploitable, retombe sur
 * une décimation uniforme.
 *
 * Garanties contractuelles (utilisées pour alléger les pages Highcharts sur les
 * longues périodes, cf. docs/AUDIT_GRAPHIQUES_HIGHCHARTS.md §5) :
 *  - le PREMIER et le DERNIER point sont toujours conservés ;
 *  - l'ordre temporel (l'ordre des index d'origine) est préservé ;
 *  - le nombre d'index retournés ne dépasse JAMAIS le plafond ;
 *  - si la série est déjà <= plafond, l'identité est renvoyée (rétro-compat).
 *
 * Le cœur de l'API renvoie une LISTE D'INDEX sélectionnés : on l'applique
 * ensuite à toutes les colonnes d'un même jeu de lectures, ce qui garantit la
 * cohérence timestamps ↔ valeurs (mêmes index retenus partout).
 */
final class SeriesDownsampler
{
    /**
     * Plafond de points par défaut au-delà duquel une série est décimée.
     *
     * Choisi pour rester nettement sous le pire cas observé à l'audit
     * (serre ~4900 pts) tout en conservant une résolution visuelle confortable.
     */
    public const DEFAULT_MAX_POINTS = 2000;

    /**
     * Calcule les index à conserver pour ramener une série de longueur $count
     * sous le plafond $maxPoints.
     *
     * @param list<int|float|null> $xValues Axe X (ex. timestamps ms). Sert de référence LTTB.
     * @param list<int|float|null> $yValues Axe Y (valeurs). Sert au calcul d'aire LTTB.
     * @return list<int> Index croissants à conserver (1er et dernier inclus)
     */
    public static function selectIndices(array $xValues, array $yValues, int $maxPoints): array
    {
        $count = count($xValues);

        // En dessous du plafond (ou plafond non contraignant) : identité.
        if ($maxPoints < 3 || $count <= $maxPoints) {
            return range(0, $count - 1);
        }

        // LTTB nécessite un axe X et un axe Y numériques exploitables.
        if (count($yValues) !== $count || !self::isUsableNumericAxis($xValues)) {
            return self::uniformIndices($count, $maxPoints);
        }

        return self::lttbIndices($xValues, $yValues, $maxPoints);
    }

    /**
     * Applique une sélection d'index à une liste de valeurs (réindexée).
     *
     * @param list<mixed> $values
     * @param list<int> $indices
     * @return list<mixed>
     */
    public static function applyIndices(array $values, array $indices): array
    {
        $out = [];
        foreach ($indices as $i) {
            $out[] = $values[$i] ?? null;
        }

        return $out;
    }

    /**
     * Sous-échantillonne un tableau de lectures (lignes associatives) en
     * décimant sur l'axe temporel. Toutes les colonnes restent alignées.
     *
     * @param list<array<string, mixed>> $readings  Lectures (ordre quelconque, préservé)
     * @param string $timeKey   Colonne timestamp/date servant d'axe X
     * @param string $valueKey  Colonne numérique servant d'axe Y pour LTTB
     * @return list<array<string, mixed>>
     */
    public static function downsampleReadings(
        array $readings,
        string $timeKey,
        string $valueKey,
        int $maxPoints
    ): array {
        $count = count($readings);
        if ($maxPoints < 3 || $count <= $maxPoints) {
            return array_values($readings);
        }

        $x = [];
        $y = [];
        $index = 0;
        foreach ($readings as $row) {
            // X : l'index ordinal suffit comme axe régulier (l'ordre des lectures
            // matérialise déjà la progression temporelle) ; cela évite de devoir
            // re-parser des dates et reste robuste aux pas irréguliers.
            $x[] = $index++;
            $raw = $row[$valueKey] ?? null;
            $y[] = is_numeric($raw) ? (float) $raw : null;
        }

        $indices = self::selectIndices($x, $y, $maxPoints);

        $rows = array_values($readings);
        $out = [];
        foreach ($indices as $i) {
            $out[] = $rows[$i];
        }

        return $out;
    }

    /**
     * Décimation uniforme préservant 1er et dernier point.
     *
     * @return list<int>
     */
    private static function uniformIndices(int $count, int $maxPoints): array
    {
        if ($maxPoints >= $count) {
            return range(0, $count - 1);
        }

        $indices = [0];
        // (maxPoints - 2) points intermédiaires régulièrement espacés.
        $inner = $maxPoints - 2;
        $step = ($count - 1) / ($maxPoints - 1);
        for ($i = 1; $i <= $inner; $i++) {
            $idx = (int) round($i * $step);
            if ($idx <= $indices[count($indices) - 1]) {
                $idx = $indices[count($indices) - 1] + 1;
            }
            if ($idx >= $count - 1) {
                break;
            }
            $indices[] = $idx;
        }
        $indices[] = $count - 1;

        return $indices;
    }

    /**
     * Cœur LTTB. Renvoie les index sélectionnés (1er + buckets + dernier).
     *
     * @param list<int|float|null> $x
     * @param list<int|float|null> $y
     * @return list<int>
     */
    private static function lttbIndices(array $x, array $y, int $threshold): array
    {
        $count = count($x);

        $sampledIndices = [0];

        // Taille des buckets pour les points intermédiaires (hors 1er et dernier).
        $bucketSize = ($count - 2) / ($threshold - 2);

        $a = 0; // index du point précédemment retenu
        for ($i = 0; $i < $threshold - 2; $i++) {
            // Bornes du bucket courant (points candidats).
            $rangeStart = (int) floor(($i + 0) * $bucketSize) + 1;
            $rangeEnd = (int) floor(($i + 1) * $bucketSize) + 1;
            $rangeEnd = min($rangeEnd, $count - 1);

            // Bornes du bucket suivant (pour le point moyen).
            $avgRangeStart = (int) floor(($i + 1) * $bucketSize) + 1;
            $avgRangeEnd = (int) floor(($i + 2) * $bucketSize) + 1;
            $avgRangeEnd = min($avgRangeEnd, $count);

            // Point moyen du bucket suivant.
            $avgX = 0.0;
            $avgY = 0.0;
            $avgCount = 0;
            for ($j = $avgRangeStart; $j < $avgRangeEnd; $j++) {
                $cx = self::num($x[$j]);
                $cy = self::num($y[$j]);
                if ($cy === null) {
                    continue;
                }
                $avgX += $cx ?? (float) $j;
                $avgY += $cy;
                $avgCount++;
            }
            if ($avgCount > 0) {
                $avgX /= $avgCount;
                $avgY /= $avgCount;
            } else {
                // Bucket suivant sans valeur exploitable : on vise son centre en X.
                $avgX = (float) (int) (($avgRangeStart + $avgRangeEnd) / 2);
                $avgY = 0.0;
            }

            // Point A (dernier retenu).
            $ax = self::num($x[$a]) ?? (float) $a;
            $ay = self::num($y[$a]) ?? 0.0;

            // Plus grande aire de triangle dans le bucket courant.
            $maxArea = -1.0;
            $selected = $rangeStart;
            for ($j = $rangeStart; $j < $rangeEnd; $j++) {
                $px = self::num($x[$j]) ?? (float) $j;
                $py = self::num($y[$j]);
                $pyVal = $py ?? $ay;
                $area = abs(
                    ($ax - $avgX) * ($pyVal - $ay)
                    - ($ax - $px) * ($avgY - $ay)
                ) * 0.5;
                if ($area > $maxArea) {
                    $maxArea = $area;
                    $selected = $j;
                }
            }

            $sampledIndices[] = $selected;
            $a = $selected;
        }

        $sampledIndices[] = $count - 1;

        return $sampledIndices;
    }

    /**
     * Vérifie qu'au moins deux valeurs de l'axe sont numériques (LTTB exploitable).
     *
     * @param list<int|float|null> $axis
     */
    private static function isUsableNumericAxis(array $axis): bool
    {
        $numeric = 0;
        foreach ($axis as $v) {
            if ($v !== null && is_numeric($v)) {
                $numeric++;
                if ($numeric >= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function num(int|float|null $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
