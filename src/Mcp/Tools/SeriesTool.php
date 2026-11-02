<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\Support\TimeWindow;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;

/**
 * `iot_series` — série temporelle agrégée côté SQL (≤ 500 points) + statistiques par champ.
 */
final class SeriesTool extends AbstractTool
{
    public function __construct(McpConfig $config, private McpReadRepository $repo)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_series';
    }

    public function title(): string
    {
        return 'Série temporelle agrégée';
    }

    public function description(): string
    {
        return 'Série temporelle d\'un ou plusieurs champs d\'une famille, agrégée par intervalle côté base (moyenne, min ou max '
            . 'par bucket de 5 min, 15 min, 1 h, 6 h ou 1 jour ; « auto » choisit le plus fin qui reste ≤ 500 points). '
            . 'Fenêtre maximale 180 jours (défaut : dernières 24 h). Renvoie pour chaque bucket {t, n (nb de mesures), valeurs} '
            . 'et, par champ : min, max, moyenne, écart-type, nombre de valeurs, première et dernière moyenne de bucket. '
            . 'Les buckets sans mesure sont absents (trou = module hors ligne ou en veille). Niveaux d\'eau FFP3 en cm.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema(array_merge([
            'family' => $this->familyProperty(),
            'fields' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 8,
                'uniqueItems' => true,
                'items' => ['type' => 'string', 'enum' => FieldCatalog::allNumericFields()],
                'description' => 'Champs numériques de la famille (voir iot_describe), 1 à 8.',
            ],
        ], $this->windowProperties('-24h'), [
            'interval' => [
                'type' => 'string',
                'enum' => array_merge(['auto'], array_keys(TimeWindow::INTERVALS)),
                'default' => 'auto',
                'description' => 'Largeur des buckets. « auto » : le plus fin donnant ≤ 500 points.',
            ],
            'agg' => [
                'type' => 'string',
                'enum' => ['avg', 'min', 'max'],
                'default' => 'avg',
                'description' => 'Agrégat par bucket.',
            ],
            'env' => $this->envProperty(),
        ]), ['family', 'fields']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $family = (string) $arguments['family'];
        /** @var list<string> $fields */
        $fields = array_values(array_map('strval', (array) $arguments['fields']));
        $unknown = array_diff($fields, FieldCatalog::numericFields($family));
        if ($unknown !== []) {
            throw new ToolException(sprintf(
                'Champ(s) inconnu(s) pour %s : %s. Champs disponibles : %s.',
                $family,
                implode(', ', $unknown),
                implode(', ', FieldCatalog::numericFields($family))
            ), 'unknown_field');
        }
        $window = TimeWindow::parse(
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null
        );
        $interval = $window->resolveInterval((string) ($arguments['interval'] ?? 'auto'));
        $agg = (string) ($arguments['agg'] ?? 'avg');

        return $this->inEnv($arguments, function () use ($family, $fields, $window, $interval, $agg): array {
            $rows = $this->repo->aggregate($family, $fields, $window->fromSql(), $window->toSql(), $interval['seconds'], $agg);
            $points = [];
            $perField = array_fill_keys($fields, []);
            foreach ($rows as $row) {
                $point = ['t' => McpReadRepository::bucketToDate((int) $row['bucket_ts']), 'n' => (int) $row['n']];
                foreach ($fields as $field) {
                    $value = FieldCatalog::present($family, $field, $row[$field] ?? null);
                    $point[$field] = $value;
                    if ($value !== null) {
                        $perField[$field][] = (float) $value;
                    }
                }
                $points[] = $point;
            }
            $stats = [];
            foreach ($perField as $field => $values) {
                $stats[$field] = self::summarize($values) + ['unit' => FieldCatalog::unit($family, $field)];
            }

            return [
                'family' => $family,
                'window' => $window->describe(),
                'interval' => $interval['name'],
                'agg' => $agg,
                'points_count' => count($points),
                'stats' => $stats,
                'points' => $points,
            ];
        }, $family);
    }

    /**
     * @param list<float> $values
     * @return array<string, mixed>
     */
    public static function summarize(array $values): array
    {
        $n = count($values);
        if ($n === 0) {
            return ['count' => 0, 'min' => null, 'max' => null, 'mean' => null, 'stddev' => null, 'first' => null, 'last' => null];
        }
        $mean = array_sum($values) / $n;
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }

        return [
            'count' => $n,
            'min' => round(min($values), 3),
            'max' => round(max($values), 3),
            'mean' => round($mean, 3),
            'stddev' => round(sqrt($variance / $n), 3),
            'first' => round($values[0], 3),
            'last' => round($values[$n - 1], 3),
        ];
    }
}
