<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Repository\McpHealthRepository;

/**
 * `iot_health_history` — derniers rapports de santé stockés par le CRON et évolution du statut.
 */
final class HealthHistoryTool extends AbstractTool
{
    public function __construct(McpConfig $config, private McpHealthRepository $repo)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_health_history';
    }

    public function title(): string
    {
        return 'Historique des rapports de santé';
    }

    public function description(): string
    {
        return 'Les N derniers rapports de santé enregistrés par le CRON (quotidien par défaut) : date, statut vert / orange / '
            . 'rouge, résumé, raisons, e-mail envoyé ou non ; plus l\'évolution du statut (changements, nombre de jours par '
            . 'statut, statut courant depuis quand). Utile pour savoir depuis quand un problème dure ou s\'il revient. '
            . 'Vide si le rapport régulier n\'est pas activé (MCP_HEALTH_REPORT_ENABLED) : utiliser alors iot_health_report.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'default' => 14, 'description' => 'Nombre de rapports (du plus récent au plus ancien).'],
            'include_reports' => ['type' => 'boolean', 'default' => false, 'description' => 'Joindre le rapport complet du plus récent.'],
        ]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $limit = (int) ($arguments['limit'] ?? 14);
        try {
            $rows = $this->repo->latest($limit, false);
            $latestFull = ($arguments['include_reports'] ?? false) === true ? ($this->repo->latest(1, true)[0]['report'] ?? null) : null;
        } catch (\PDOException) {
            throw new ToolException(
                'Historique indisponible : table mcp_health_reports absente (jouer migrations/2026_11_mcp_exploitation.sql).',
                'not_configured'
            );
        }

        return ToolResult::json([
            'count' => count($rows),
            'evolution' => self::evolution($rows),
            'reports' => $rows,
            'latest_report' => $latestFull,
            'regular_report_enabled' => $this->config->isHealthReportEnabled(),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows du plus récent au plus ancien
     * @return array<string, mixed>
     */
    public static function evolution(array $rows): array
    {
        if ($rows === []) {
            return ['current' => null, 'since' => null, 'changes' => [], 'counts' => ['vert' => 0, 'orange' => 0, 'rouge' => 0], 'trend' => null];
        }
        $chrono = array_reverse($rows);
        $counts = ['vert' => 0, 'orange' => 0, 'rouge' => 0];
        $changes = [];
        $previous = null;
        foreach ($chrono as $row) {
            $status = (string) $row['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if ($previous !== null && $previous !== $status) {
                $changes[] = ['at' => $row['generated_at'], 'from' => $previous, 'to' => $status];
            }
            $previous = $status;
        }
        $current = (string) $rows[0]['status'];
        $since = (string) $rows[0]['generated_at'];
        foreach ($rows as $row) {
            if ((string) $row['status'] !== $current) {
                break;
            }
            $since = (string) $row['generated_at'];
        }
        $rank = ['vert' => 0, 'orange' => 1, 'rouge' => 2];
        $trend = count($rows) < 2 ? null : match (($rank[$current] ?? 0) <=> ($rank[(string) $rows[1]['status']] ?? 0)) {
            -1 => 'amelioration',
            1 => 'degradation',
            default => 'stable',
        };

        return ['current' => $current, 'since' => $since, 'changes' => $changes, 'counts' => $counts, 'trend' => $trend];
    }
}
