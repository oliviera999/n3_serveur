<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Décision PURE de coupure « problème de marée » (CronOrchestrator::checkTideSystem).
 *
 * Avant 6.56.0, un écart-type faible suffisait à couper la pompe aquarium. Or un écart-type
 * NUL est aussi ce que produit un capteur figé (même valeur répétée) et un STDDEV de valeurs
 * presque toutes absentes : incident du 30/10/2026 (docs/DIAGNOSTIC_FFP3_NIVEAU_AQUARIUM_2026-11.md),
 * 196 coupures en 37 h sur une valeur figée à 81 mm, puis silence total quand la fenêtre est
 * devenue entièrement NULL. La pompe ne doit être coupée que sur une donnée VALIDE et VIVANTE.
 *
 * Ordre des gardes :
 *  1. aucune valeur non nulle            → capteur absent (pas de coupure) ;
 *  2. couverture < $minCoverage          → données insuffisantes (pas de coupure) ;
 *  3. écart-type ≥ seuil                 → marées normales ;
 *  4. une seule valeur distincte         → capteur figé (pas de coupure) ;
 *  5. coupures 24 h ≥ $maxCutsPerDay     → disjoncteur ouvert (pas de coupure, alerte) ;
 *  6. sinon                              → coupure (redémarrage différé inchangé).
 */
final class TideCutGuard
{
    public const CUT = 'cut';
    public const OK = 'ok';
    public const SENSOR_ABSENT = 'sensor_absent';
    public const INSUFFICIENT_DATA = 'insufficient_data';
    public const SENSOR_FROZEN = 'sensor_frozen';
    public const BREAKER_OPEN = 'breaker_open';

    /**
     * @param array{rows: int, non_null: int, distinct: int, stddev: float|null} $window
     */
    public static function decide(
        array $window,
        float $stddevThreshold,
        float $minCoverage,
        int $cutsLast24h,
        int $maxCutsPerDay,
    ): string {
        $rows = max(0, $window['rows']);
        $nonNull = max(0, $window['non_null']);
        if ($rows === 0 || $nonNull === 0) {
            return self::SENSOR_ABSENT;
        }
        if ($nonNull / $rows < $minCoverage || $window['stddev'] === null) {
            return self::INSUFFICIENT_DATA;
        }
        if ($window['stddev'] >= $stddevThreshold) {
            return self::OK;
        }
        if ($window['distinct'] <= 1) {
            return self::SENSOR_FROZEN;
        }
        if ($maxCutsPerDay > 0 && $cutsLast24h >= $maxCutsPerDay) {
            return self::BREAKER_OPEN;
        }

        return self::CUT;
    }
}
