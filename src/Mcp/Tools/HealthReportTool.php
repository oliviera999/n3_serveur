<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Health\HealthReportBuilder;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\ToolResult;

/**
 * `iot_health_report` — rapport de santé du site, construit à la demande (lecture seule).
 */
final class HealthReportTool extends AbstractTool
{
    public function __construct(McpConfig $config, private HealthReportBuilder $builder)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_health_report';
    }

    public function title(): string
    {
        return 'Rapport de santé du site';
    }

    public function description(): string
    {
        return 'Rapport de santé complet d\'iot.olution.info, construit maintenant : statut global vert / orange / rouge avec '
            . 'les règles déclenchées (raisons), résumé d\'une ligne, puis le détail : version applicative et PHP, latence BDD, '
            . 'taille des tables principales, CRON (dernier passage, retards des sous-tâches), chaque appareil de production '
            . '(en ligne / hors ligne, dernier heartbeat, seuil, firmware), erreurs serveur et notifications sur 24 h et 7 j, '
            . 'dernière photo par galerie, espace disque libre, jours avant expiration du certificat TLS, connexions MCP '
            . 'actives. À utiliser pour un diagnostic d\'ensemble ; iot_health_history donne l\'évolution dans le temps.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'check_tls' => ['type' => 'boolean', 'default' => true, 'description' => 'Contrôler le certificat TLS (connexion sortante, ≤ 3 s).'],
            'summary_only' => ['type' => 'boolean', 'default' => false, 'description' => 'Ne renvoyer que le statut, le résumé et les raisons.'],
        ]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $result = $this->builder->build(($arguments['check_tls'] ?? true) === true);
        $data = [
            'status' => $result['status'],
            'summary' => $result['summary'],
            'reasons' => $result['reasons'],
        ];
        if (($arguments['summary_only'] ?? false) !== true) {
            $data['report'] = $result['report'];
        }

        return ToolResult::json($data);
    }
}
