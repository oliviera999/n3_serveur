<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

/**
 * Décision PURE « capteur absent / figé » sur les dernières valeurs d'une colonne.
 *
 * Né de l'incident FFP3 du 30/10/2026 (docs/DIAGNOSTIC_FFP3_NIVEAU_AQUARIUM_2026-11.md) : EauAquarium
 * figé 33 h à 81 mm (le firmware re-postait sa dernière valeur faute d'écho), puis NULL plusieurs
 * jours (champ omis du POST) — sans qu'aucune alerte ne le signale, alors que la supervision des
 * marées, du niveau bas et du trop-plein en dépend.
 *
 *  - ABSENT : les `$minAbsentReadings` lignes les plus récentes sont TOUTES vides ;
 *  - FIGÉ   : la valeur la plus récente se répète à l'identique sur au moins `$frozenMinReadings`
 *             lignes ET couvre au moins `$frozenMinSeconds` (des vides isolés n'interrompent pas
 *             le figement). Une vraie mesure d'aquarium varie avec les marées.
 *
 * Aucune E/S : l'appelant fournit les lignes (plus récente d'abord) et porte les effets de bord.
 */
final class SensorPresenceEvaluator
{
    public const STATUS_OK = 'ok';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_FROZEN = 'frozen';

    /**
     * @param list<array{value: mixed, reading_time: string}> $rows plus récente d'abord
     * @return array{status: string, readings: int, since: string|null, last_value: float|null}
     */
    public static function evaluate(
        array $rows,
        int $minAbsentReadings,
        int $frozenMinReadings,
        int $frozenMinSeconds,
    ): array {
        $minAbsentReadings = max(1, $minAbsentReadings);
        $frozenMinReadings = max(2, $frozenMinReadings);
        $total = count($rows);
        $isNull = static fn (mixed $v): bool => $v === null || $v === '' || !is_numeric($v);

        // Série de vides en tête (lignes les plus récentes).
        $nulls = 0;
        while ($nulls < $total && $isNull($rows[$nulls]['value'])) {
            $nulls++;
        }
        $lastValue = $nulls < $total ? (float) $rows[$nulls]['value'] : null;

        if ($nulls >= $minAbsentReadings) {
            return [
                'status' => self::STATUS_ABSENT,
                'readings' => $nulls,
                'since' => $rows[$nulls - 1]['reading_time'],
                'last_value' => $lastValue,
            ];
        }
        if ($lastValue === null) {
            return ['status' => self::STATUS_OK, 'readings' => 0, 'since' => null, 'last_value' => null];
        }

        // Répétition de la dernière valeur non nulle.
        $equal = 0;
        $oldest = $rows[$nulls]['reading_time'];
        for ($i = $nulls; $i < $total; $i++) {
            $v = $rows[$i]['value'];
            if ($isNull($v)) {
                continue;
            }
            if (abs((float) $v - $lastValue) > 1e-9) {
                break;
            }
            $equal++;
            $oldest = $rows[$i]['reading_time'];
        }
        $span = max(0, (int) strtotime($rows[$nulls]['reading_time']) - (int) strtotime($oldest));
        if ($equal >= $frozenMinReadings && $span >= $frozenMinSeconds) {
            return ['status' => self::STATUS_FROZEN, 'readings' => $equal, 'since' => $oldest, 'last_value' => $lastValue];
        }

        return ['status' => self::STATUS_OK, 'readings' => 0, 'since' => null, 'last_value' => $lastValue];
    }
}
