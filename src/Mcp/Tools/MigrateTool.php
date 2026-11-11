<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Confirm\ConfirmationGate;
use App\Mcp\Confirm\ConfirmCategory;
use App\Mcp\InsufficientScopeException;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Sql\MigrationCatalog;
use App\Mcp\Sql\MigrationFile;
use App\Mcp\Sql\MigrationRunner;
use App\Mcp\Sql\SchemaReader;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `iot_migrate` — ADMIN, ÉCRITURE BORNÉE : applique un fichier `migrations/*.sql` du DÉPÔT, et lui seul.
 *
 * Plus étroit que iot_sql (SQL libre) : aucun SQL fourni par l'appelant ; seules les migrations dont
 * TOUTES les instructions sont additives ({@see MigrationFile} : ADD COLUMN / ADD INDEX / CREATE TABLE /
 * CREATE INDEX) sont applicables. Les instructions déjà en place (schéma réel) et celles visant une
 * table absente sont sautées : rejouer est sans effet.
 *
 * Activé par défaut depuis 6.61.0 (MCP_MIGRATIONS_ENABLED=true ; false pour le retirer), scope iot.control, rôle admin, plan d'abord
 * (action « plan », défaut), puis application avec confirmation par code hors Claude (catégorie
 * sql_migration). plan_all / apply_all : toutes les migrations additives en attente, un seul code.
 * Journal [mcp-migrate] + audit de contrôle. Exécution : {@see MigrationRunner} (partagée avec le CRON
 * {@see \App\Command\AutoMigrateCommand}, qui applique seul les migrations additives après un déploiement).
 */
final class MigrateTool extends AbstractTool
{
    private MigrationRunner $runner;

    public function __construct(
        McpConfig $config,
        PDO $pdo,
        SchemaReader $schema,
        private MigrationCatalog $migrations,
        private ConfirmationGate $confirm,
        private ControlAuditLogger $audit,
        private LogService $logger,
        ?MigrationRunner $runner = null,
    ) {
        parent::__construct($config);
        $this->runner = $runner ?? new MigrationRunner($pdo, $schema, $migrations);
    }

    public function name(): string
    {
        return 'iot_migrate';
    }

    public function title(): string
    {
        return 'Appliquer une migration du dépôt (admin)';
    }

    public function description(): string
    {
        return 'ADMIN — applique un fichier migrations/<nom>.sql DU DÉPÔT (jamais de SQL libre). Seules les migrations '
            . 'entièrement ADDITIVES sont acceptées (ADD COLUMN, ADD INDEX, CREATE TABLE, CREATE INDEX) ; DROP, UPDATE, '
            . 'DELETE, MODIFY… restent à phpMyAdmin. action « plan » (défaut) : instructions et état réel (applied / pending / '
            . 'partial / table_missing), rien n\'est exécuté. action « apply » : exécute les seules instructions en attente, '
            . 'après confirmation par code envoyé par e-mail (confirmation_id + confirmation_code). Rejouer est sans effet. '
            . 'action « plan_all » / « apply_all » (sans « file ») : toutes les migrations additives en attente, un seul code. '
            . 'Le CRON du serveur applique aussi seul les migrations additives en attente (AUTO_MIGRATE_ENABLED). '
            . 'État de toutes les migrations : iot_db_schema avec « migrations »: true.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function requiredScope(): string
    {
        return McpConfig::SCOPE_READ;
    }

    public function isAvailableFor(McpContext $context): bool
    {
        return $this->config->isMigrationsEnabled() && $context->isAdmin();
    }

    public function annotations(): array
    {
        return [
            'title' => $this->title(),
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'file' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.-]{0,120}\.sql$', 'description' => 'Nom du fichier dans migrations/.'],
            'action' => ['type' => 'string', 'enum' => ['plan', 'apply', 'plan_all', 'apply_all'], 'default' => 'plan'],
            'confirmation_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
            'confirmation_code' => ['type' => 'string', 'pattern' => '^[0-9]{6}$'],
        ]);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);
        $action = (string) ($arguments['action'] ?? 'plan');
        if ($action === 'plan_all' || $action === 'apply_all') {
            return $this->callAll($action, $arguments, $context);
        }
        if (!isset($arguments['file']) || !is_string($arguments['file']) || $arguments['file'] === '') {
            throw new ToolException('« file » est requis pour les actions plan et apply (ou utiliser plan_all / apply_all).', 'missing_file');
        }
        $file = $arguments['file'];
        $inspected = $this->migrations->inspect($file);
        $runnable = MigrationRunner::runnable($inspected);
        $plan = [
            'file' => $file,
            'status' => $inspected['status'],
            'additive_only' => $inspected['additive_only'],
            'to_execute' => count($runnable),
            'statements' => $inspected['statements'],
        ];

        if ($action !== 'apply') {
            return ToolResult::json($plan + [
                'executed' => false,
                'note' => $inspected['additive_only']
                    ? ($runnable === [] ? 'Rien à faire : migration déjà appliquée (ou tables absentes).' : 'Pour appliquer : action « apply » (code de confirmation par e-mail).')
                    : 'Migration non additive : non applicable par le connecteur (phpMyAdmin).',
            ]);
        }

        if (!$context->hasScope(McpConfig::SCOPE_CONTROL)) {
            throw new InsufficientScopeException(McpConfig::SCOPE_CONTROL);
        }
        $blocker = MigrationRunner::blocker($inspected);
        if ($blocker !== null && $blocker['code'] === 'migration_not_additive') {
            throw new ToolException($blocker['message'], $blocker['code']);
        }
        if ($runnable === []) {
            return ToolResult::json($plan + ['executed' => false, 'note' => 'Rien à faire : déjà appliquée.']);
        }
        if ($blocker !== null) {
            throw new ToolException($blocker['message'], $blocker['code']);
        }

        $pending = $this->confirm->check($this->name(), $arguments, $context, [ConfirmCategory::SQL_MIGRATION], [
            'cible' => 'Migration ' . $file . ' → ' . implode(', ', self::tables($runnable)),
            'effet' => sprintf('%d instruction(s) DDL additive(s) en PRODUCTION (base courante)', count($runnable)),
            'risques' => ['DDL : verrou possible le temps de l\'ALTER sur les grosses tables'],
        ]);
        if ($pending !== null) {
            return $pending;
        }

        $outcome = $this->runner->apply($file);
        $this->journal($context, $file, $outcome);

        return ToolResult::json([
            'file' => $file,
            'executed' => true,
            'ok' => $outcome['ok'],
            'results' => $outcome['results'],
            'status_after' => $outcome['status_after'],
        ]);
    }

    /**
     * plan_all : toutes les migrations additives en attente ; apply_all : les applique toutes (dans
     * l'ordre alphabétique des fichiers) avec UN seul code de confirmation. Les fichiers bloqués
     * (partiels non idempotents) sont listés et sautés ; arrêt au premier fichier en échec.
     *
     * @param array<string, mixed> $arguments
     */
    private function callAll(string $action, array $arguments, McpContext $context): ToolResult
    {
        $files = [];
        $blocked = [];
        $count = 0;
        $tables = [];
        foreach ($this->runner->pendingFiles() as $file) {
            $inspected = $this->migrations->inspect($file);
            $runnable = MigrationRunner::runnable($inspected);
            $blocker = MigrationRunner::blocker($inspected);
            $entry = ['file' => $file, 'status' => $inspected['status'], 'to_execute' => count($runnable), 'tables' => self::tables($runnable)];
            if ($blocker !== null) {
                $blocked[] = $entry + ['blocked' => $blocker['message']];
                continue;
            }
            $files[] = $entry;
            $count += count($runnable);
            $tables = array_merge($tables, self::tables($runnable));
        }
        $plan = ['pending_files' => $files, 'blocked_files' => $blocked, 'to_execute' => $count];

        if ($action === 'plan_all' || $files === []) {
            return ToolResult::json($plan + [
                'executed' => false,
                'note' => $files === []
                    ? 'Aucune migration additive en attente applicable.'
                    : 'Pour tout appliquer : action « apply_all » (un seul code de confirmation par e-mail).',
            ]);
        }

        if (!$context->hasScope(McpConfig::SCOPE_CONTROL)) {
            throw new InsufficientScopeException(McpConfig::SCOPE_CONTROL);
        }
        $pending = $this->confirm->check($this->name(), $arguments, $context, [ConfirmCategory::SQL_MIGRATION], [
            'cible' => count($files) . ' migration(s) : ' . implode(', ', array_column($files, 'file')),
            'effet' => sprintf('%d instruction(s) DDL additive(s) en PRODUCTION sur %s', $count, implode(', ', array_values(array_unique($tables)))),
            'risques' => ['DDL : verrou possible le temps de l\'ALTER sur les grosses tables'],
        ]);
        if ($pending !== null) {
            return $pending;
        }

        $outcomes = [];
        $ok = true;
        foreach ($files as $entry) {
            $outcome = $this->runner->apply($entry['file']);
            $this->journal($context, $entry['file'], $outcome);
            $outcomes[] = $outcome;
            if (!$outcome['ok']) {
                $ok = false;
                break;
            }
        }

        return ToolResult::json([
            'executed' => true,
            'ok' => $ok,
            'files' => $outcomes,
            'blocked_files' => $blocked,
            'still_pending' => $this->runner->pendingFiles(),
        ]);
    }

    /**
     * @param array{ok: bool, executed: int, results: list<array<string, mixed>>} $outcome
     */
    private function journal(McpContext $context, string $file, array $outcome): void
    {
        $this->logger->info('[mcp-migrate] user={user} client={client} file={file} ok={ok} executed={n}', [
            'user' => $context->username,
            'client' => $context->clientId,
            'file' => $file,
            'ok' => $outcome['ok'] ? '1' : '0',
            'n' => (string) $outcome['executed'],
        ]);
        $request = $context->request ?? (new ServerRequestFactory())->createServerRequest('POST', '/mcp');
        $this->audit->logAction($request, 'mcp:migrate', $file, ['results' => $outcome['results']], $outcome['ok'], null, 'mcp:' . $context->username);
    }

    /**
     * @param list<array<string, mixed>> $statements
     * @return list<string>
     */
    private static function tables(array $statements): array
    {
        return array_values(array_unique(array_map(static fn (array $s): string => (string) $s['table'], $statements)));
    }
}
