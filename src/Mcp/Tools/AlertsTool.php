<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Support\ActiveAlertsReader;
use App\Mcp\Support\Redactor;
use App\Mcp\Support\TimeWindow;
use App\Mcp\ToolResult;
use App\Repository\McpReadRepository;

/**
 * `iot_alerts` — alertes en cours + historique des notifications et des erreurs serveur.
 */
final class AlertsTool extends AbstractTool
{
    public function __construct(
        McpConfig $config,
        private McpReadRepository $repo,
        private ActiveAlertsReader $active,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_alerts';
    }

    public function title(): string
    {
        return 'Alertes et notifications';
    }

    public function description(): string
    {
        return 'Trois vues : (1) alertes EN COURS tenues par la supervision serveur (appareil hors ligne, trop-plein, batterie '
            . 'faible, sol sec, gel, canicule, pluie, pompe bloquée, coupure marée en cours) ; (2) historique des notifications '
            . 'envoyées (clé, gravité critical/alert/info/diagnostic, catégorie, sujet, date — destinataires jamais exposés) ; '
            . '(3) erreurs serveur regroupées par message normalisé (nombre d\'occurrences, première/dernière). Filtres : '
            . 'famille, gravité, période (défaut 7 j, max 180 j), limite.';
    }

    public function inputSchema(): array
    {
        return $this->objectSchema(array_merge([
            'family' => ['type' => 'string', 'enum' => ['ffp3', 'n3pp', 'msp1'], 'description' => 'Restreindre à une famille.'],
            'severity' => ['type' => 'string', 'enum' => ['critical', 'alert', 'info', 'diagnostic'], 'description' => 'Restreindre à une gravité.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50, 'description' => 'Nombre maximal d\'entrées par liste.'],
            'include_server_errors' => ['type' => 'boolean', 'default' => true, 'description' => 'Inclure les erreurs serveur (error_alerts).'],
        ], $this->windowProperties('-7d')));
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        $window = TimeWindow::parse(
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null,
            7 * 86400
        );
        $family = isset($arguments['family']) ? (string) $arguments['family'] : null;
        $severity = isset($arguments['severity']) ? (string) $arguments['severity'] : null;
        $limit = (int) ($arguments['limit'] ?? 50);

        $active = array_values(array_filter($this->active->active(), static function (array $a) use ($family, $severity): bool {
            return ($family === null || ($a['family'] ?? '') === $family)
                && ($severity === null || ($a['severity'] ?? '') === $severity);
        }));

        $notifications = array_map(static fn (array $r): array => [
            'key' => (string) $r['alert_key'],
            'severity' => (string) $r['severity'],
            'category' => $r['category'],
            'subject' => Redactor::redact(is_string($r['subject'] ?? null) ? $r['subject'] : null, 200),
            'sent_at' => (string) $r['sent_at'],
        ], $this->repo->notificationLog($window->fromSql(), $window->toSql(), $family, $severity, $limit));

        $errors = [];
        if (($arguments['include_server_errors'] ?? true) === true) {
            $errors = array_map(static fn (array $r): array => [
                'message' => Redactor::redact((string) $r['normalized_message'], 250),
                'occurrences' => (int) $r['occurrences'],
                'first_seen' => $r['first_seen'],
                'last_seen' => $r['last_seen'],
                'last_alerted' => $r['last_alerted'],
            ], $this->repo->errorAlerts($window->fromSql(), $window->toSql(), $limit));
        }

        return ToolResult::json([
            'window' => $window->describe(),
            'active' => $active,
            'notifications' => $notifications,
            'server_errors' => $errors,
            'note' => 'Les alertes en cours reflètent l\'environnement par défaut du serveur (celui du CRON).',
        ]);
    }
}
