<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\Sql\SqlReader;
use App\Mcp\Sql\SqlStatementClassifier;
use App\Mcp\Support\SecretScanner;
use App\Mcp\ToolException;
use App\Mcp\ToolResult;
use App\Service\LogService;

/**
 * `iot_sql_select` — ADMIN, LECTURE SQL SEULE (SELECT / WITH / SHOW / DESCRIBE / EXPLAIN), sans le mode
 * productivité : sépare la lecture de l'écriture (iot_sql), sur le modèle sql_select / sql_execute de
 * ForetMap.
 *
 * Garde-fous : classification {@see SqlStatementClassifier} (une instruction, mots-clés interdits,
 * colonnes secrètes refusées), exécution sous transaction READ ONLY (McpServer + connexion lecture),
 * délai borné, lignes plafonnées, secrets et e-mails masqués ({@see SqlReader}). Désactivable :
 * MCP_SQL_SELECT_ENABLED=false.
 */
final class SqlSelectTool extends AbstractTool
{
    public function __construct(McpConfig $config, private SqlReader $reader, private LogService $logger)
    {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'iot_sql_select';
    }

    public function title(): string
    {
        return 'Lecture SQL (admin)';
    }

    public function description(): string
    {
        return 'ADMIN, lecture seule — exécute UNE instruction SQL de lecture (SELECT, WITH, SHOW, DESCRIBE, EXPLAIN) en '
            . 'transaction READ ONLY, délai borné, max_rows lignes au plus (plafond serveur). Toute écriture est refusée '
            . '(utiliser iot_sql, mode productivité). Colonnes secrètes interdites ; secrets, jetons et e-mails masqués. '
            . 'Tables par environnement : ffp3Data = prod, ffp3Data2 = test, ffp3DataS3 = aquamobile (production aussi) ; '
            . 'liste et colonnes : iot_db_schema, sens des champs : iot_describe.';
    }

    public function isAvailableFor(McpContext $context): bool
    {
        return $this->config->isSqlSelectEnabled() && $context->isAdmin();
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'sql' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000, 'description' => 'Une instruction de lecture.'],
            'max_rows' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'description' => 'Lignes renvoyées au plus.'],
        ], ['sql']);
    }

    public function call(array $arguments, McpContext $context): ToolResult
    {
        AdminGuard::assertAdmin($context);
        $meta = SqlStatementClassifier::classify((string) $arguments['sql']);
        if ($meta['class'] !== SqlStatementClassifier::CLASS_READ) {
            throw new ToolException(
                'iot_sql_select n\'accepte que des lectures (instruction de classe « ' . $meta['class'] . ' »). Écriture : iot_sql.',
                'sql_not_read_only'
            );
        }
        $maxRows = min((int) ($arguments['max_rows'] ?? $this->config->sqlMaxRows()), $this->config->sqlMaxRows());
        $started = microtime(true);
        $data = $this->reader->select($meta['sql'], max(1, $maxRows), $context->canSeePersonalData());
        $duration = (int) round((microtime(true) - $started) * 1000);

        $this->logger->info('[mcp-sql-select] user={user} client={client} sha={sha} rows={rows} ms={ms} sql={sql}', [
            'user' => $context->username,
            'client' => $context->clientId,
            'sha' => hash('sha256', $meta['sql']),
            'rows' => (string) count($data['rows']),
            'ms' => (string) $duration,
            'sql' => mb_substr(SecretScanner::mask($meta['sql']), 0, 300),
        ]);

        return ToolResult::json([
            'columns' => $data['columns'],
            'rows' => $data['rows'],
            'rowCount' => count($data['rows']),
            'truncated' => $data['truncated'],
            'tables' => $meta['tables'],
            'duration_ms' => $duration,
        ]);
    }
}
