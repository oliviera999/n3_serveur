<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;

/**
 * `iot_pgl_stats` — compteur Poissonglouton (bouteilles plastiques recyclées).
 */
final class PglStatsTool extends AbstractTool
{
    public function __construct(McpConfig $config, private McpReadRepository $repo)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_pgl_stats';
    }

    public function title(): string
    {
        return 'Statistiques Poissonglouton';
    }

    public function description(): string
    {
        return 'Compteur Poissonglouton (poisson géant qui « avale » les déchets plastiques déposés par les élèves, détectés par '
            . 'capteurs IR / ultrasons) : total depuis l\'origine, total du jour, totaux par jour (jusqu\'à 365 j) et par heure '
            . '(jusqu\'à 168 h), dernier événement (mode capteur, batterie, firmware) et dernier heartbeat. Tables uniques, '
            . 'sans variante d\'environnement.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30, 'description' => 'Nombre de jours de l\'historique quotidien.'],
            'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 168, 'default' => 48, 'description' => 'Nombre d\'heures de l\'historique horaire.'],
        ]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $stats = $this->repo->pglStats((int) ($arguments['days'] ?? 30), (int) ($arguments['hours'] ?? 48));
        if (($stats['available'] ?? false) !== true) {
            return ToolResult::json(['available' => false, 'note' => 'Tables Poissonglouton absentes ou illisibles.']);
        }
        $hourly = array_map(static fn (array $r): array => [
            't' => McpReadRepository::bucketToDate((int) $r['bucket_ts']),
            'count' => (int) $r['total'],
        ], (array) $stats['hourly']);
        $daily = array_map(static fn (array $r): array => ['day' => (string) $r['day'], 'count' => (int) $r['total']], (array) $stats['daily']);

        return ToolResult::json([
            'is_production' => true,
            'available' => true,
            'total' => $stats['total'],
            'today' => $stats['today'],
            'latest_event' => $stats['latest_event'],
            'last_heartbeat' => $stats['last_heartbeat'],
            'daily' => $daily,
            'hourly' => $hourly,
        ]);
    }
}
