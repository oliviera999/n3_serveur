<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\Support\TimeWindow;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;

/**
 * `iot_stats` — statistiques de période pour tous les champs numériques d'une famille.
 */
final class StatsTool extends AbstractTool
{
    public function __construct(McpConfig $config, private McpReadRepository $repo)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_stats';
    }

    public function title(): string
    {
        return 'Statistiques sur une période';
    }

    public function description(): string
    {
        return 'Statistiques calculées en base sur une période (défaut : 7 derniers jours, max 180 j) pour chaque champ '
            . 'numérique d\'une famille (ou une sélection) : min, max, moyenne, écart-type, nombre de valeurs non vides. '
            . 'Indique aussi le nombre de mesures, la première et la dernière, et la couverture (mesures reçues par heure) '
            . 'pour repérer les trous. Un écart-type nul sur une longue période suggère un capteur figé.';
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
                'description' => 'Champs à inclure (défaut : tous les champs numériques de la famille).',
            ],
        ], $this->windowProperties('-7d'), ['env' => $this->envProperty()]), ['family']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $family = (string) $arguments['family'];
        $fields = isset($arguments['fields'])
            ? array_values(array_intersect(array_map('strval', (array) $arguments['fields']), FieldCatalog::numericFields($family)))
            : FieldCatalog::numericFields($family);
        $window = TimeWindow::parse(
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null,
            7 * 86400
        );

        return $this->inEnv($arguments, function () use ($family, $fields, $window): array {
            $row = $fields === [] ? [] : $this->repo->stats($family, $fields, $window->fromSql(), $window->toSql());
            $rows = (int) ($row['rows'] ?? 0);
            $stats = [];
            foreach ($fields as $field) {
                $count = (int) ($row[$field . '__n'] ?? 0);
                if ($count === 0) {
                    continue;
                }
                $scale = FieldCatalog::scale($family, $field);
                $std = $row[$field . '__std'] ?? null;
                $stats[$field] = [
                    'unit' => FieldCatalog::unit($family, $field),
                    'count' => $count,
                    'min' => FieldCatalog::present($family, $field, $row[$field . '__min'] ?? null),
                    'max' => FieldCatalog::present($family, $field, $row[$field . '__max'] ?? null),
                    'mean' => FieldCatalog::present($family, $field, $row[$field . '__avg'] ?? null),
                    'stddev' => is_numeric($std) ? round((float) $std * $scale, 3) : null,
                ];
            }
            $hours = max(1.0, $window->spanSeconds() / 3600);

            return [
                'family' => $family,
                'window' => $window->describe(),
                'readings' => $rows,
                'first_reading' => $row['first'] ?? null,
                'last_reading' => $row['last'] ?? null,
                'readings_per_hour' => round($rows / $hours, 2),
                'fields' => $stats,
                'empty_fields' => array_values(array_diff($fields, array_keys($stats))),
            ];
        }, $family);
    }
}
