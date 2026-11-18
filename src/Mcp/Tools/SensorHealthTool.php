<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Config\SensorFieldMap;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\Support\TimeWindow;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;

/**
 * `iot_sensor_health` — santé PAR CAPTEUR sur la fin d'une fenêtre : capteur muet (série de NULL
 * en cours), intermittent, ou figé (même valeur répétée), avec la dernière valeur non nulle, le
 * début du silence / du figement et le taux de couverture.
 *
 * Né du diagnostic FFP3 du 30/10/2026 (docs/DIAGNOSTIC_FFP3_NIVEAU_AQUARIUM_2026-11.md) : EauAquarium
 * figé 33 h à 81 mm puis NULL plusieurs jours. Reconstituer cela demandait plusieurs appels croisés
 * à iot_series / iot_stats ; cet outil le donne en une lecture.
 *
 * Lecture seule ({@see \App\Mcp\Support\ReadOnlyTransaction}) : une requête bornée sur les lignes
 * les plus récentes (plafond {@see McpReadRepository::MAX_RAW_ROWS}), analyse en PHP.
 */
final class SensorHealthTool extends AbstractTool
{
    public const MAX_SPAN_DAYS = 30;
    /**
     * Fenêtre analysée par défaut — PARTAGÉE avec le rapport de santé
     * ({@see \App\Mcp\Health\HealthReportBuilder}) pour que la date « figé depuis » soit la même.
     */
    public const DEFAULT_WINDOW_SECONDS = 48 * 3600;
    public const DEFAULT_FROZEN_MINUTES = 120;
    /** Mesures identiques consécutives minimales pour parler de valeur figée. */
    public const MIN_FROZEN_READINGS = 5;
    /** NULL consécutifs (en fin de fenêtre) à partir desquels le capteur est déclaré absent. */
    public const MIN_ABSENT_READINGS = 3;
    /** Capteur mis hors service par le firmware (diagnostic d'écho) : information, jamais une alerte. */
    public const STATUS_DISABLED = SensorFieldMap::STATUS_DISABLED;

    public function __construct(McpConfig $config, private McpReadRepository $repo)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_sensor_health';
    }

    public function title(): string
    {
        return 'Santé des capteurs (muet, figé)';
    }

    public function description(): string
    {
        return 'Diagnostic par capteur sur la fin d\'une période (défaut : 48 dernières heures, max '
            . self::MAX_SPAN_DAYS . ' j). Pour chaque champ : statut « ok », « intermittent » (1 ou 2 mesures vides '
            . 'en fin de période), « absent » (au moins ' . self::MIN_ABSENT_READINGS . ' mesures vides d\'affilée, ou aucune valeur), '
            . '« frozen » = figé (même valeur répétée sur au moins frozen_minutes et ' . self::MIN_FROZEN_READINGS . ' mesures), '
            . '« disabled » = capteur mis hors service par le firmware (diagnostic d\'écho wlAquaStatus / wlPotaStatus '
            . '= disabled sur la dernière mesure : la valeur répétée n\'est pas une mesure, information sans alerte), '
            . 'ou « no_data » (aucune mesure reçue). La réserve (EauReserve) n\'est déclarée figée que si la pompe de '
            . 'réserve a tourné pendant la répétition (immobile pompe à l\'arrêt = normal). Donne aussi la dernière valeur non nulle et sa date, le début et la durée '
            . 'de la série vide, le début et la durée du figement de cette dernière valeur, et la couverture '
            . '(part de mesures non vides). Par défaut, porte sur les capteurs physiques de la famille (sans luminosité '
            . 'ni réglages, légitimement constants). « frozen » est un INDICE : une grandeur réellement stable peut le déclencher. '
            . 'Un capteur muet n\'est pas une mesure à 0, et les contrôles serveur (marées, niveau bas, trop-plein) '
            . 'sont aveugles sur lui.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema(array_merge([
            'family' => $this->familyProperty(),
            'fields' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 30,
                'uniqueItems' => true,
                'items' => ['type' => 'string', 'enum' => FieldCatalog::allNumericFields()],
                'description' => 'Champs à analyser (défaut : capteurs physiques de la famille).',
            ],
            'frozen_minutes' => [
                'type' => 'integer',
                'minimum' => 10,
                'maximum' => 10080,
                'default' => self::DEFAULT_FROZEN_MINUTES,
                'description' => 'Durée minimale (minutes) d\'une même valeur répétée pour la déclarer figée.',
            ],
        ], $this->windowProperties('-' . intdiv(self::DEFAULT_WINDOW_SECONDS, 3600) . 'h'), ['env' => $this->envProperty()]), ['family']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $family = (string) $arguments['family'];
        $fields = isset($arguments['fields'])
            ? array_values(array_intersect(array_map('strval', (array) $arguments['fields']), FieldCatalog::numericFields($family)))
            : FieldCatalog::sensorFields($family);
        $frozenMinutes = isset($arguments['frozen_minutes']) ? (int) $arguments['frozen_minutes'] : self::DEFAULT_FROZEN_MINUTES;
        $frozenMinutes = max(10, min(10080, $frozenMinutes));
        $window = TimeWindow::parse(
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null,
            self::DEFAULT_WINDOW_SECONDS,
            self::MAX_SPAN_DAYS
        );

        return $this->inEnv($arguments, function () use ($family, $fields, $frozenMinutes, $window): array {
            // Statut d'écho et activité (pompe de réserve) lus avec les capteurs : analyse() en dépend.
            $columns = array_merge($fields, SensorFieldMap::auxiliaryColumns($family, $fields));
            $rows = $fields === [] ? [] : $this->repo->recentRows($family, $columns, $window->fromSql(), $window->toSql());
            $truncated = count($rows) >= McpReadRepository::MAX_RAW_ROWS;
            $result = [];
            foreach ($fields as $field) {
                $result[$field] = self::analyse($family, $field, $rows, $frozenMinutes * 60, $truncated);
            }
            $suspects = array_keys(array_filter($result, static fn (array $f): bool => !in_array($f['status'], ['ok', self::STATUS_DISABLED], true)));
            $disabled = array_keys(array_filter($result, static fn (array $f): bool => $f['status'] === self::STATUS_DISABLED));

            return [
                'family' => $family,
                'window' => $window->describe(),
                'readings' => count($rows),
                'first_reading' => $rows !== [] ? $rows[count($rows) - 1]['reading_time'] : null,
                'last_reading' => $rows[0]['reading_time'] ?? null,
                'truncated' => $truncated,
                'frozen_minutes' => $frozenMinutes,
                'fields' => $result,
                'suspect_fields' => $suspects,
                'disabled_fields' => $disabled,
                'note' => ($suspects === []
                    ? 'Aucun capteur muet ni figé sur la fin de la période.'
                    : 'Capteur(s) suspect(s) : ' . implode(', ', $suspects) . '. Une date « depuis » égale au début de la '
                        . 'période (since_is_lower_bound) signifie « au moins depuis » : élargir « from » pour la dater.')
                    . ($disabled === [] ? '' : ' Désactivé(s) par le firmware (information, pas une panne serveur) : ' . implode(', ', $disabled) . '.'),
            ];
        }, $family);
    }

    /**
     * Analyse d'un champ sur des lignes triées de la plus RÉCENTE à la plus ancienne.
     *
     * Si les lignes portent les colonnes auxiliaires ({@see SensorFieldMap::auxiliaryColumns()}) :
     *  - statut d'écho `disabled` sur la dernière mesure → statut `disabled` (ni figé, ni muet) ;
     *  - colonne d'activité (pompe de réserve pour EauReserve) → une valeur répétée n'est « figée »
     *    que si l'activité a eu lieu pendant la répétition (avant sa dernière mesure).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function analyse(string $family, string $field, array $rows, int $frozenSeconds, bool $truncated = false): array
    {
        $unit = FieldCatalog::unit($family, $field);
        $total = count($rows);
        if ($total === 0) {
            return ['status' => 'no_data', 'unit' => $unit, 'readings' => 0];
        }
        $isNull = static fn (mixed $v): bool => $v === null || $v === '' || !is_numeric($v);
        $nonNull = 0;
        foreach ($rows as $r) {
            if (!$isNull($r[$field] ?? null)) {
                $nonNull++;
            }
        }

        // 1. Série de NULL en fin de fenêtre (lignes les plus récentes).
        $i = 0;
        while ($i < $total && $isNull($rows[$i][$field] ?? null)) {
            $i++;
        }
        $nullReadings = $i;
        $nullSince = $nullReadings > 0 ? (string) $rows[$nullReadings - 1]['reading_time'] : null;
        $latestTime = (string) $rows[0]['reading_time'];

        $out = [
            'status' => 'ok',
            'unit' => $unit,
            'readings' => $total,
            'coverage' => round($nonNull / $total, 3),
            'last_value' => null,
            'last_seen' => null,
            'null_readings' => $nullReadings,
            'null_since' => $nullSince,
            'null_for_seconds' => $nullSince !== null ? max(0, (int) strtotime($latestTime) - (int) strtotime($nullSince)) : null,
            'frozen_readings' => 0,
            'frozen_since' => null,
            'frozen_for_seconds' => null,
            'since_is_lower_bound' => false,
        ];

        if ($i === $total) {
            // Aucune valeur sur toute la fenêtre.
            $out['status'] = 'absent';
            $out['since_is_lower_bound'] = true;

            return self::applyEchoStatus($family, $field, $rows, $out);
        }

        // 2. Dernière valeur non nulle, puis remontée tant qu'elle se répète (les NULL intermédiaires
        //    ne rompent pas le figement : un capteur figé puis muet reste lisible).
        $lastRaw = (float) $rows[$i][$field];
        $lastSeen = (string) $rows[$i]['reading_time'];
        $frozenReadings = 0;
        $frozenSince = $lastSeen;
        $changed = false;
        for ($j = $i; $j < $total; $j++) {
            $v = $rows[$j][$field] ?? null;
            if ($isNull($v)) {
                continue;
            }
            if (abs((float) $v - $lastRaw) > 1e-9) {
                $changed = true;
                break;
            }
            $frozenReadings++;
            $frozenSince = (string) $rows[$j]['reading_time'];
        }
        $frozenFor = max(0, (int) strtotime($lastSeen) - (int) strtotime($frozenSince));

        $out['last_value'] = FieldCatalog::present($family, $field, $lastRaw);
        $out['last_seen'] = $lastSeen;
        $out['frozen_readings'] = $frozenReadings;
        $out['frozen_since'] = $frozenSince;
        $out['frozen_for_seconds'] = $frozenFor;
        $out['since_is_lower_bound'] = !$changed;
        if ($truncated && !$changed) {
            $out['note'] = 'Fenêtre plafonnée aux mesures les plus récentes : le figement peut être plus ancien.';
        }

        $frozen = $frozenReadings >= self::MIN_FROZEN_READINGS && $frozenFor >= $frozenSeconds;
        $activityColumn = SensorFieldMap::frozenActivityColumn($family, $field);
        if ($frozen && $activityColumn !== null && array_key_exists($activityColumn, $rows[$i])) {
            // Lignes de la répétition, de la plus récente ($i) à la plus ancienne ($j - 1) : une
            // activité sur la plus récente n'a pas encore pu faire bouger le niveau.
            $active = false;
            for ($k = $i + 1; $k < $j; $k++) {
                $a = $rows[$k][$activityColumn] ?? null;
                if (is_numeric($a) && (float) $a > 0) {
                    $active = true;
                    break;
                }
            }
            $out['activity_column'] = $activityColumn;
            $out['activity_during_repeat'] = $active;
            if (!$active) {
                $frozen = false;
                $out['note'] = sprintf('Valeur stable sans %s pendant la répétition : normal (rien ne la fait varier).', $activityColumn);
            }
        }

        if ($nullReadings >= self::MIN_ABSENT_READINGS) {
            $out['status'] = 'absent';
        } elseif ($nullReadings > 0) {
            $out['status'] = 'intermittent';
        } elseif ($frozen) {
            $out['status'] = 'frozen';
        }

        return self::applyEchoStatus($family, $field, $rows, $out);
    }

    /**
     * Diagnostic d'écho firmware : `disabled` sur la DERNIÈRE mesure l'emporte sur figé / muet /
     * intermittent (le firmware répète sa dernière valeur ou n'en envoie plus). Le statut reste
     * visible pour qu'on sache que le capteur est hors service ; ce n'est pas une alerte.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private static function applyEchoStatus(string $family, string $field, array $rows, array $out): array
    {
        $column = SensorFieldMap::echoStatusColumn($family, $field);
        if ($column === null || $rows === [] || !array_key_exists($column, $rows[0])) {
            return $out;
        }
        $latest = $rows[0][$column];
        $out['echo_status'] = is_string($latest) && $latest !== '' ? $latest : null;
        if (!SensorFieldMap::isDisabledStatus($latest)) {
            return $out;
        }
        $since = (string) $rows[0]['reading_time'];
        $lowerBound = true;
        foreach ($rows as $row) {
            if (!SensorFieldMap::isDisabledStatus($row[$column] ?? null)) {
                $lowerBound = false;
                break;
            }
            $since = (string) $row['reading_time'];
        }
        $out['status'] = self::STATUS_DISABLED;
        $out['disabled_since'] = $since;
        $out['disabled_since_is_lower_bound'] = $lowerBound;
        $out['note'] = sprintf(
            'Capteur désactivé par le firmware (%s = disabled) : la valeur répétée n\'est pas une mesure. Information, pas une alerte.',
            $column
        );

        return $out;
    }
}
