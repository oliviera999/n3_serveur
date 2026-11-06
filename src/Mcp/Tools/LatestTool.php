<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;

/**
 * `iot_latest` — dernière mesure complète d'une famille.
 *
 * Les champs vides sont omis de `values` ; un CAPTEUR PHYSIQUE muet ({@see FieldCatalog::sensorFields()})
 * est en plus listé dans `missing_fields` avec sa dernière valeur non nulle et la durée du silence.
 * Sans cela, un capteur absent disparaissait de la réponse sans aucun signal (incident FFP3
 * EauAquarium NULL du 31/10/2026, docs/DIAGNOSTIC_FFP3_NIVEAU_AQUARIUM_2026-11.md).
 */
final class LatestTool extends AbstractTool
{
    /** Profondeur de recherche de la dernière valeur non nulle (bornée : index reading_time). */
    public const MISSING_LOOKBACK_DAYS = 30;

    public function __construct(McpConfig $config, private McpReadRepository $repo)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_latest';
    }

    public function title(): string
    {
        return 'Dernière mesure d\'un module';
    }

    public function description(): string
    {
        return 'Renvoie la dernière ligne de mesures d\'une famille avec TOUS les champs du dictionnaire (unités converties : '
            . 'niveaux d\'eau FFP3 en cm), sa date, son âge en secondes et la version firmware. Les champs vides sont omis de '
            . '« values » ; un capteur physique vide est listé dans « missing_fields » avec sa dernière valeur non nulle '
            . '(recherche sur ' . self::MISSING_LOOKBACK_DAYS . ' j), depuis quand il est vide et sur combien de mesures. '
            . 'Diagnostic détaillé (valeur figée, intermittence) : iot_sensor_health. '
            . 'Voir iot_describe pour la signification des champs.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'family' => $this->familyProperty(),
            'env' => $this->envProperty(),
        ], ['family']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $family = (string) $arguments['family'];

        return $this->inEnv($arguments, function () use ($family): array {
            $row = $this->repo->latest($family);
            if ($row === null) {
                return ['family' => $family, 'reading' => null, 'note' => 'Aucune mesure enregistrée.'];
            }
            $values = [];
            foreach (FieldCatalog::fields($family) as $field => $def) {
                $value = FieldCatalog::present($family, $field, $row[$field] ?? null);
                if ($value !== null) {
                    $values[$field] = $def['unit'] !== '' ? ['value' => $value, 'unit' => $def['unit']] : ['value' => $value];
                }
            }

            $missing = $this->missingSensors($family, $row, (string) $row['reading_time']);

            return array_merge([
                'family' => $family,
                'reading_time' => $row['reading_time'],
                'age_seconds' => self::ageSeconds((string) $row['reading_time']),
                'firmware_version' => $row['version'] ?? null,
                'values' => $values,
            ], $missing === [] ? [] : [
                'missing_fields' => $missing,
                'note' => 'Capteur(s) physique(s) sans valeur dans la dernière mesure : voir missing_fields '
                    . '(un capteur muet n\'est pas une mesure à 0). Détail : iot_sensor_health.',
            ]);
        }, $family);
    }

    /**
     * Capteurs physiques vides dans la dernière ligne : dernière valeur non nulle, date du début
     * du silence (première ligne reçue après elle), nombre de mesures concernées.
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function missingSensors(string $family, array $row, string $latestTime): array
    {
        $since = date('Y-m-d H:i:s', (int) strtotime($latestTime) - self::MISSING_LOOKBACK_DAYS * 86400);
        $out = [];
        foreach (FieldCatalog::sensorFields($family) as $field) {
            if (FieldCatalog::present($family, $field, $row[$field] ?? null) !== null) {
                continue;
            }
            $last = $this->repo->lastNonNull($family, $field, $since);
            $after = $this->repo->rowsAfter($family, $last !== null ? $last['reading_time'] : date('Y-m-d H:i:s', (int) strtotime($since) - 1));
            $nullSince = $after['first'];
            $out[] = [
                'field' => $field,
                'unit' => FieldCatalog::unit($family, $field),
                'last_value' => $last !== null ? FieldCatalog::present($family, $field, $last['value']) : null,
                'last_seen' => $last['reading_time'] ?? null,
                'null_since' => $nullSince,
                'null_for_seconds' => $nullSince !== null ? max(0, (int) strtotime($latestTime) - (int) strtotime($nullSince)) : null,
                'null_readings' => $after['count'],
                'lookback_exceeded' => $last === null,
            ];
        }

        return $out;
    }
}
