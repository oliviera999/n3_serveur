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
 * Désactivé par défaut (MCP_MIGRATIONS_ENABLED=false), scope iot.control, rôle admin, plan d'abord
 * (action « plan », défaut), puis application avec confirmation par code hors Claude (catégorie
 * sql_migration). Journal [mcp-migrate] + audit de contrôle.
 */
final class MigrateTool extends AbstractTool
{
    public function __construct(
        McpConfig $config,
        private PDO $pdo,
        private SchemaReader $schema,
        private MigrationCatalog $migrations,
        private ConfirmationGate $confirm,
        private ControlAuditLogger $audit,
        private LogService $logger,
    ) {
        parent::__construct($config);
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
            'action' => ['type' => 'string', 'enum' => ['plan', 'apply'], 'default' => 'plan'],
            'confirmation_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
            'confirmation_code' => ['type' => 'string', 'pattern' => '^[0-9]{6}$'],
        ], ['file']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);
        $file = (string) $arguments['file'];
        $action = (string) ($arguments['action'] ?? 'plan');
        $inspected = $this->migrations->inspect($file);

        $runnable = array_values(array_filter(
            $inspected['statements'],
            static fn (array $s): bool => in_array($s['status'], [MigrationCatalog::ST_PENDING, MigrationCatalog::ST_PARTIAL], true)
        ));
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
        if (!$inspected['additive_only']) {
            throw new ToolException('Migration non additive (DROP, UPDATE, DELETE, MODIFY…) : à appliquer via phpMyAdmin.', 'migration_not_additive');
        }
        if ($runnable === []) {
            return ToolResult::json($plan + ['executed' => false, 'note' => 'Rien à faire : déjà appliquée.']);
        }
        foreach ($runnable as $s) {
            if ($s['status'] === MigrationCatalog::ST_PARTIAL && !$s['idempotent']) {
                throw new ToolException(
                    'Instruction partiellement appliquée sur ' . $s['table'] . ' sans « IF NOT EXISTS » : rejouer échouerait. '
                    . 'Compléter à la main (manquant : ' . implode(', ', $s['missing']) . ').',
                    'migration_partial'
                );
            }
        }

        $pending = $this->confirm->check($this->name(), $arguments, $context, [ConfirmCategory::SQL_MIGRATION], [
            'cible' => 'Migration ' . $file . ' → ' . implode(', ', array_values(array_unique(array_map(
                static fn (array $s): string => (string) $s['table'],
                $runnable
            )))),
            'effet' => sprintf('%d instruction(s) DDL additive(s) en PRODUCTION (base courante)', count($runnable)),
            'risques' => ['DDL : verrou possible le temps de l\'ALTER sur les grosses tables'],
        ]);
        if ($pending !== null) {
            return $pending;
        }

        // Instructions brutes (non tronquées), dans l'ordre du fichier, ré-évaluées une par une.
        $results = [];
        $failed = false;
        foreach ($this->migrations->statements($file) as $raw) {
            [$status] = $this->migrations->statementStatus($raw);
            if (!in_array($status, [MigrationCatalog::ST_PENDING, MigrationCatalog::ST_PARTIAL], true)) {
                $results[] = ['table' => $raw['table'], 'status' => $status, 'executed' => false];
                continue;
            }
            $started = microtime(true);
            try {
                $this->pdo->exec($raw['sql']);
                $this->schema->forget();
                $results[] = ['table' => $raw['table'], 'status' => 'done', 'executed' => true, 'ms' => (int) round((microtime(true) - $started) * 1000)];
            } catch (\Throwable $e) {
                $failed = true;
                $results[] = ['table' => $raw['table'], 'status' => 'error', 'executed' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
                break;
            }
        }

        $this->logger->info('[mcp-migrate] user={user} client={client} file={file} ok={ok} executed={n}', [
            'user' => $context->username,
            'client' => $context->clientId,
            'file' => $file,
            'ok' => $failed ? '0' : '1',
            'n' => (string) count(array_filter($results, static fn (array $r): bool => $r['executed'])),
        ]);
        $request = $context->request ?? (new ServerRequestFactory())->createServerRequest('POST', '/mcp');
        $this->audit->logAction($request, 'mcp:migrate', $file, ['results' => $results], !$failed, null, 'mcp:' . $context->username);

        $this->schema->forget();
        $after = $this->migrations->inspect($file);

        return ToolResult::json([
            'file' => $file,
            'executed' => true,
            'ok' => !$failed,
            'results' => $results,
            'status_after' => $after['status'],
        ]);
    }
}
