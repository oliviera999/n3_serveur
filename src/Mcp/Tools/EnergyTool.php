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
 * `iot_energy` — bilan énergétique du banc INA226 sur une période.
 */
final class EnergyTool extends AbstractTool
{
    public function __construct(McpConfig $config, private McpReadRepository $repo)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_energy';
    }

    public function title(): string
    {
        return 'Bilan énergétique (banc solaire)';
    }

    public function description(): string
    {
        return 'Bilan du banc énergie INA226 (panneau solaire, batterie plomb 12 V, consommation) sur une période (défaut 24 h, '
            . 'max 180 j) : énergie produite et consommée (Wh, somme des deltas de 10 s), bilan net, puissances moyennes et '
            . 'maximales, tension batterie min/moy/max, état de charge au début et à la fin, courant batterie (> 0 charge, '
            . '< 0 décharge). Le banc de test est l\'environnement « energie_test » s\'il est autorisé.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema(array_merge($this->windowProperties('-24h'), ['env' => $this->envProperty()]));
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $window = TimeWindow::parse(
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null
        );

        return $this->inEnv($arguments, function () use ($window): array {
            $from = $window->fromSql();
            $to = $window->toSql();
            $sums = $this->repo->sums('energie', ['EnergiePanneauWh', 'EnergieConsoWh'], $from, $to);
            $stats = $this->repo->stats('energie', ['PanneauP', 'ConsoP', 'BatterieV', 'BatterieI', 'BatterieSoc', 'Rssi'], $from, $to);
            $soc = $this->repo->firstAndLast('energie', 'BatterieSoc', $from, $to);
            $produced = $sums['EnergiePanneauWh'] ?? null;
            $consumed = $sums['EnergieConsoWh'] ?? null;
            $metric = static fn (string $f, string $k): ?float => is_numeric($stats[$f . '__' . $k] ?? null)
                ? round((float) $stats[$f . '__' . $k], 3) : null;

            return [
                'family' => 'energie',
                'window' => $window->describe(),
                'readings' => (int) ($stats['rows'] ?? 0),
                'energy_wh' => [
                    'produced' => $produced !== null ? round($produced, 3) : null,
                    'consumed' => $consumed !== null ? round($consumed, 3) : null,
                    'net' => $produced !== null && $consumed !== null ? round($produced - $consumed, 3) : null,
                ],
                'panel_power_w' => ['mean' => $metric('PanneauP', 'avg'), 'max' => $metric('PanneauP', 'max')],
                'load_power_w' => ['mean' => $metric('ConsoP', 'avg'), 'max' => $metric('ConsoP', 'max')],
                'battery' => [
                    'voltage_v' => ['min' => $metric('BatterieV', 'min'), 'mean' => $metric('BatterieV', 'avg'), 'max' => $metric('BatterieV', 'max')],
                    'current_a' => ['min' => $metric('BatterieI', 'min'), 'mean' => $metric('BatterieI', 'avg'), 'max' => $metric('BatterieI', 'max')],
                    'soc_percent' => ['start' => $soc['first'], 'end' => $soc['last'], 'min' => $metric('BatterieSoc', 'min')],
                ],
                'wifi_rssi_dbm_mean' => $metric('Rssi', 'avg'),
                'hint' => FieldCatalog::fields('energie')['BatterieV']['meaning'],
            ];
        }, 'energie');
    }
}
