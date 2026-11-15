<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Décision PURE « aquarium bas » (FFP3, `EauAquarium` = distance capteur→surface en mm : plus grand =
 * eau plus basse) : une alerte P1 ne doit reposer que sur des mesures FIABLES et CONFIRMÉES.
 *
 * Né des ~37 e-mails « [FFP3][P1] Niveau d'eau aquarium bas » du 11 au 13/11/2026 : capteur
 * intermittent (59 % de mesures valides, réponses `no_echo`, tirs sans écho), e-mail toutes les
 * 15-20 min la nuit du 11 au 12, et le 13/11 à 12:05 sur 152 mm (seuil 150 mm) juste après une
 * série de `no_echo` — fausses alertes probables.
 *
 * Fenêtre = `$rows` dernières mesures (plus récente d'abord ; colonnes `EauAquarium`,
 * `wlAquaStatus`, `wlAquaTimeouts` — diagnostic d'écho ffp5cs ≥ 15.33, null si absent) :
 *  - NO_DATA    : dernière mesure vide → rien (l'alerte « capteur absent » couvre ce cas) ;
 *  - NORMAL     : dernière mesure ≤ seuil ;
 *  - UNRELIABLE : dernière mesure > seuil mais capteur non fiable sur la fenêtre — statut d'écho ≠ ok,
 *                 tirs sans écho (`wlAquaTimeouts` > max), couverture de mesures valides insuffisante,
 *                 ou niveau bas non confirmé sur `$confirmReadings` mesures consécutives ;
 *  - LOW        : niveau bas fiable et confirmé → alerte P1 légitime.
 *
 * Un statut d'écho absent (firmware < 15.33, colonnes non migrées) n'est pas un défaut : seuls la
 * couverture et la confirmation jugent alors la fiabilité.
 */
final class WaterLowGuard
{
    public const NO_DATA = 'no_data';
    public const NORMAL = 'normal';
    public const UNRELIABLE = 'unreliable';
    public const LOW = 'low';

    /**
     * @param list<array<string, mixed>> $rows plus récente d'abord
     * @return array{decision: string, level: float|null, reasons: list<string>, valid: int, rows: int, coverage: float}
     */
    public static function assess(
        array $rows,
        float $thresholdMm,
        int $confirmReadings = 3,
        float $minCoverage = 0.8,
        int $maxEchoTimeouts = 0,
    ): array {
        $total = count($rows);
        $value = static fn (array $row): ?float => isset($row['EauAquarium']) && is_numeric($row['EauAquarium'])
            ? (float) $row['EauAquarium'] : null;
        $valid = 0;
        foreach ($rows as $row) {
            if ($value($row) !== null) {
                $valid++;
            }
        }
        $coverage = $total > 0 ? $valid / $total : 0.0;
        $level = $total > 0 ? $value($rows[0]) : null;
        $out = ['decision' => self::NO_DATA, 'level' => $level, 'reasons' => [], 'valid' => $valid, 'rows' => $total, 'coverage' => round($coverage, 3)];

        if ($level === null) {
            return $out;
        }
        if ($level <= $thresholdMm) {
            return ['decision' => self::NORMAL] + $out;
        }

        $reasons = [];
        if ($coverage < $minCoverage) {
            $reasons[] = sprintf('couverture %d/%d mesures valides (%d %% < %d %%)', $valid, $total, (int) round($coverage * 100), (int) round($minCoverage * 100));
        }
        $badStatus = [];
        $timeouts = 0;
        foreach ($rows as $row) {
            $status = isset($row['wlAquaStatus']) && is_string($row['wlAquaStatus']) && $row['wlAquaStatus'] !== '' ? $row['wlAquaStatus'] : null;
            if ($status !== null && $status !== 'ok') {
                $badStatus[$status] = ($badStatus[$status] ?? 0) + 1;
            }
            if (isset($row['wlAquaTimeouts']) && is_numeric($row['wlAquaTimeouts']) && (int) $row['wlAquaTimeouts'] > $maxEchoTimeouts) {
                $timeouts++;
            }
        }
        if ($badStatus !== []) {
            $parts = [];
            foreach ($badStatus as $status => $n) {
                $parts[] = $status . ' ×' . $n;
            }
            $reasons[] = 'écho en échec sur la fenêtre (' . implode(', ', $parts) . ')';
        }
        if ($timeouts > 0) {
            $reasons[] = sprintf('%d mesure(s) avec des tirs sans écho (wlAquaTimeouts > %d)', $timeouts, $maxEchoTimeouts);
        }
        $confirmReadings = max(1, $confirmReadings);
        $confirmed = $total >= $confirmReadings;
        for ($i = 0; $confirmed && $i < $confirmReadings; $i++) {
            $v = $value($rows[$i]);
            if ($v === null || $v <= $thresholdMm) {
                $confirmed = false;
            }
        }
        if (!$confirmed) {
            $reasons[] = sprintf('niveau bas non confirmé sur %d mesures consécutives', $confirmReadings);
        }

        return ['decision' => $reasons === [] ? self::LOW : self::UNRELIABLE, 'reasons' => $reasons] + $out;
    }
}
