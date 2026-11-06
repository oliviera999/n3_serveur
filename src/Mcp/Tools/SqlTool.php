<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Confirm\ConfirmationGate;
use App\Mcp\Control\ControlTarget;
use App\Mcp\InsufficientScopeException;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Sql\SqlReader;
use App\Mcp\Sql\SqlStatementClassifier;
use App\Mcp\Support\ReadOnlyTransaction;
use App\Mcp\Support\SecretScanner;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use PDO;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `iot_sql` — SQL libre admin (une instruction, transaction, dry_run, plafonds).
 */
final class SqlTool extends AbstractTool
{
    public function __construct(
        McpConfig $config,
        private PDO $pdo,
        private PDO $readPdo,
        private ReadOnlyTransaction $readOnly,
        private ConfirmationGate $confirm,
        private ControlAuditLogger $audit,
        private LogService $logger,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_sql';
    }

    public function title(): string
    {
        return 'SQL libre (admin)';
    }

    public function description(): string
    {
        return 'ADMIN — exécute UNE instruction SQL. Lectures en transaction READ ONLY ; DML en transaction '
            . 'avec dry_run (annulation) et max_affected_rows. DDL et écritures sensibles exigent confirmation par code. '
            . 'LOAD DATA / OUTFILE / SHUTDOWN et colonnes secrètes restent interdits.';
    }

    public function isReadOnly(): bool
    {
        // Exécution gérée manuellement (lecture RO vs écriture).
        return false;
    }

    public function requiredScope(): string
    {
        return McpConfig::SCOPE_READ;
    }

    public function isAvailableFor(McpContext $context): bool
    {
        return $this->config->isSqlEnabled() && $context->isAdmin();
    }

    public function annotations(): array
    {
        return [
            'title' => $this->title(),
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ];
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'sql' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
            'dry_run' => ['type' => 'boolean', 'default' => false],
            'max_affected_rows' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
            'max_rows' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000],
            'confirmation_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
            'confirmation_code' => ['type' => 'string', 'pattern' => '^[0-9]{6}$'],
        ], ['sql']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);
        $sql = (string) $arguments['sql'];
        $dryRun = !empty($arguments['dry_run']);
        $maxAffected = min(
            (int) ($arguments['max_affected_rows'] ?? $this->config->sqlMaxAffectedRows()),
            $this->config->sqlMaxAffectedRows()
        );
        $maxRows = min(
            (int) ($arguments['max_rows'] ?? $this->config->sqlMaxRows()),
            $this->config->sqlMaxRows()
        );

        $prodOutputs = self::productionOutputTables();
        $meta = SqlStatementClassifier::classify($sql, $prodOutputs);

        if ($meta['class'] !== SqlStatementClassifier::CLASS_READ) {
            if (!$context->hasScope(McpConfig::SCOPE_CONTROL)) {
                throw new InsufficientScopeException(McpConfig::SCOPE_CONTROL);
            }
        }
        if (in_array($meta['class'], [SqlStatementClassifier::CLASS_DDL, SqlStatementClassifier::CLASS_ACCOUNT], true)
            && !$this->config->isSqlDdlEnabled()) {
            throw new ToolException('DDL / admin comptes désactivés (MCP_SQL_DDL_ENABLED=false).', 'sql_ddl_disabled');
        }

        $pending = $this->confirm->check($this->name(), $arguments, $context, $meta['needs_confirm'], [
            'cible' => 'SQL ' . $meta['class'] . ' → ' . implode(', ', $meta['tables'] ?: ['(aucune table)']),
            'effet' => $dryRun ? 'dry_run (annulation après exécution DML)' : 'exécution réelle',
            'risques' => array_filter([
                $meta['unbounded_write'] ? 'UPDATE/DELETE sans WHERE' : null,
                $meta['touches_prod_outputs'] ? 'Touche une table outputs de PRODUCTION' : null,
                $meta['class'] === SqlStatementClassifier::CLASS_DDL ? 'DDL' : null,
                $meta['class'] === SqlStatementClassifier::CLASS_ACCOUNT ? 'Administration des comptes SQL' : null,
            ]),
        ]);
        if ($pending !== null) {
            return $pending;
        }

        $started = microtime(true);
        $keepEmails = $context->canSeePersonalData();

        if ($meta['class'] === SqlStatementClassifier::CLASS_READ) {
            $data = $this->readOnly->run(fn (): array => $this->runSelect($meta['sql'], $maxRows, $keepEmails));
            $duration = (int) round((microtime(true) - $started) * 1000);
            $this->logSql($context, $meta, 0, $dryRun, false, $duration);

            return ToolResult::json([
                'class' => $meta['class'],
                'columns' => $data['columns'],
                'rows' => $data['rows'],
                'rowCount' => count($data['rows']),
                'affectedRows' => 0,
                'committed' => false,
                'dry_run' => $dryRun,
                'duration_ms' => $duration,
            ]);
        }

        if ($meta['class'] === SqlStatementClassifier::CLASS_DDL
            || $meta['class'] === SqlStatementClassifier::CLASS_ACCOUNT) {
            if ($dryRun) {
                return ToolResult::json([
                    'class' => $meta['class'],
                    'executed' => false,
                    'dry_run' => true,
                    'note' => 'DDL : dry_run = analyse seule (pas d\'exécution).',
                    'tables' => $meta['tables'],
                    'needs_confirm' => $meta['needs_confirm'],
                ]);
            }
            $affected = $this->runWrite($meta['sql'], false, $maxAffected, true);
            $duration = (int) round((microtime(true) - $started) * 1000);
            $this->logSql($context, $meta, $affected, false, true, $duration);
            $this->auditWrite($context, $meta, $affected, true);

            return ToolResult::json([
                'class' => $meta['class'],
                'affectedRows' => $affected,
                'committed' => true,
                'dry_run' => false,
                'duration_ms' => $duration,
            ]);
        }

        // DML
        $committed = !$dryRun;
        $affected = $this->runWrite($meta['sql'], $dryRun, $maxAffected, false);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $this->logSql($context, $meta, $affected, $dryRun, $committed, $duration);
        if ($committed) {
            $this->auditWrite($context, $meta, $affected, true);
        }

        return ToolResult::json([
            'class' => $meta['class'],
            'affectedRows' => $affected,
            'committed' => $committed,
            'dry_run' => $dryRun,
            'duration_ms' => $duration,
            'tables' => $meta['tables'],
        ]);
    }

    /**
     * @return list<string>
     */
    public static function productionOutputTables(): array
    {
        $tables = [];
        foreach (['ffp3', 'n3pp', 'msp1'] as $family) {
            $prod = ControlTarget::resolve($family, 'prod');
            $tables[] = $prod->table;
            if ($family === 'ffp3') {
                $s3 = ControlTarget::resolve($family, 's3');
                $tables[] = $s3->table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function runSelect(string $sql, int $maxRows, bool $keepEmails): array
    {
        $data = (new SqlReader($this->readPdo, $this->config->sqlMaxStatementSeconds()))->select($sql, $maxRows, $keepEmails);

        return ['columns' => $data['columns'], 'rows' => $data['rows']];
    }

    private function runWrite(string $sql, bool $dryRun, int $maxAffected, bool $isDdl): int
    {
        $wrapped = $this->wrapTimeout($sql, false);
        if ($isDdl) {
            $affected = $this->pdo->exec($wrapped);
            if ($affected === false) {
                throw new ToolException('Échec DDL.', 'sql_failed');
            }

            return (int) $affected;
        }

        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
        try {
            $affected = $this->pdo->exec($wrapped);
            if ($affected === false) {
                throw new ToolException('Échec DML.', 'sql_failed');
            }
            $affected = (int) $affected;
            if ($affected > $maxAffected) {
                $this->pdo->rollBack();
                throw new ToolException(sprintf(
                    'Annulé : %d lignes touchées > max_affected_rows=%d.',
                    $affected,
                    $maxAffected
                ), 'sql_max_affected');
            }
            if ($dryRun) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->commit();
            }

            return $affected;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof ToolException) {
                throw $e;
            }
            throw new ToolException('Échec SQL : ' . $e->getMessage(), 'sql_failed');
        }
    }

    private function wrapTimeout(string $sql, bool $isSelect): string
    {
        return SqlReader::withTimeout($this->pdo, $sql, $isSelect, $this->config->sqlMaxStatementSeconds());
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function logSql(McpContext $context, array $meta, int $affected, bool $dryRun, bool $committed, int $ms): void
    {
        $masked = SecretScanner::mask((string) $meta['sql']);
        $this->logger->info(
            '[mcp-sql] user={user} client={client} class={class} sha={sha} affected={aff} dry_run={dry} committed={ok} ms={ms} sql={sql}',
            [
                'user' => $context->username,
                'client' => $context->clientId,
                'class' => $meta['class'],
                'sha' => hash('sha256', (string) $meta['sql']),
                'aff' => (string) $affected,
                'dry' => $dryRun ? '1' : '0',
                'ok' => $committed ? '1' : '0',
                'ms' => (string) $ms,
                'sql' => mb_substr($masked, 0, 300),
            ]
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function auditWrite(McpContext $context, array $meta, int $affected, bool $ok): void
    {
        $request = $context->request ?? (new ServerRequestFactory())->createServerRequest('POST', '/mcp');
        $this->audit->logAction($request, 'mcp:sql', (string) $meta['class'], [
            'tables' => $meta['tables'],
            'affected' => $affected,
            'sha' => hash('sha256', (string) $meta['sql']),
        ], $ok, null, 'mcp:' . $context->username);
    }
}
