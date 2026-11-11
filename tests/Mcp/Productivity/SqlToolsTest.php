<?php

declare(strict_types=1);

namespace Tests\Mcp\Productivity;

use App\Mcp\Confirm\ConfirmationGate;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Sql\MigrationCatalog;
use App\Mcp\Sql\MigrationFile;
use App\Mcp\Sql\SchemaReader;
use App\Mcp\Sql\SqlReader;
use App\Mcp\Support\ReadOnlyTransaction;
use App\Mcp\ToolRegistry;
use App\Mcp\Tools\DbSchemaTool;
use App\Mcp\Tools\MigrateTool;
use App\Mcp\Tools\SqlSelectTool;
use App\Repository\McpConfirmRepository;
use App\Repository\UserRepository;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use App\Service\NotificationService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

/**
 * Outils SQL ciblés (6.58.0) : iot_db_schema (métadonnées + état des migrations), iot_sql_select
 * (lecture seule), iot_migrate (migrations additives du dépôt, plan puis code de confirmation).
 */
final class SqlToolsTest extends TestCase
{
    private PDO $pdo;
    private string $dir;
    /** @var list<string> */
    private array $sentBodies = [];

    protected function setUp(): void
    {
        $this->pdo = McpSqliteFixture::pdo();
        McpSqliteFixture::seedFfp3($this->pdo);
        $this->dir = sys_get_temp_dir() . '/mcp-migr-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        // SQLite : pas de « ADD COLUMN IF NOT EXISTS » ; une colonne par ALTER.
        file_put_contents($this->dir . '/2026_11_new_col.sql', "-- ajout ; commentaire avec point-virgule\nALTER TABLE ffp3Data ADD COLUMN wlAquaStatus TEXT;\nALTER TABLE ffp3Data2 ADD COLUMN wlAquaStatus TEXT;\nALTER TABLE ffp3DataS3 ADD COLUMN wlAquaStatus TEXT;\n");
        file_put_contents($this->dir . '/2026_05_already.sql', "ALTER TABLE ffp3Data ADD COLUMN tideEvent TEXT;\n");
        file_put_contents($this->dir . '/2026_07_drop.sql', "DROP TABLE ffp3Data2;\n");
        file_put_contents($this->dir . '/README.md', "pas une migration\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function server(array $env = []): McpServer
    {
        $config = McpSqliteFixture::config($env);
        $schema = new SchemaReader($this->pdo);
        $catalog = new MigrationCatalog($this->dir, $schema);
        $notifier = $this->createMock(NotificationService::class);
        $notifier->method('recipient')->willReturn('admin@example.test');
        $notifier->method('sendConfirmationCode')->willReturnCallback(function (string $to, string $subject, string $body): bool {
            $this->sentBodies[] = $body;

            return true;
        });
        $audit = $this->createMock(ControlAuditLogger::class);
        $logger = $this->createMock(LogService::class);
        $gate = new ConfirmationGate($config, new McpConfirmRepository($this->pdo), $notifier, new UserRepository($this->pdo), $audit, $logger);
        $tools = [
            new DbSchemaTool($config, $schema, $catalog),
            new SqlSelectTool($config, new SqlReader($this->pdo, 5), $logger),
            new MigrateTool($config, $this->pdo, $schema, $catalog, $gate, $audit, $logger),
        ];

        return new McpServer(new ToolRegistry($tools, $config), new ReadOnlyTransaction($this->pdo), $logger, $config);
    }

    /**
     * @param array<string, mixed> $args
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    private function call(McpServer $server, string $tool, array $args, string $role = 'admin', array $scopes = ['iot.read']): array
    {
        $ids = ['reader' => 1, 'operator' => 2, 'admin' => 3];
        $r = $server->handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]],
            new McpContext($ids[$role], $role, $role, $scopes, 'client-test')
        );

        return $r['result'] ?? $r['error'] ?? [];
    }

    private static function data(array $result): array
    {
        return json_decode((string) ($result['content'][0]['text'] ?? '{}'), true) ?? [];
    }

    // ── Analyse des migrations ─────────────────────────────────────────

    public function testMigrationParserKeepsOnlyAdditiveStatements(): void
    {
        $sql = "/* entête ; */ ALTER TABLE `t` ADD COLUMN IF NOT EXISTS `a` VARCHAR(16) DEFAULT 'x;y', ADD INDEX idx_a (a);\n"
            . "CREATE TABLE IF NOT EXISTS `n` (id INT PRIMARY KEY);\nCREATE UNIQUE INDEX u_n ON n (id);\n";
        $st = MigrationFile::parse($sql);
        self::assertCount(3, $st);
        self::assertSame(['a'], $st[0]['columns']);
        self::assertSame(['idx_a'], $st[0]['indexes']);
        self::assertSame(MigrationFile::KIND_CREATE_TABLE, $st[1]['kind']);
        self::assertSame(MigrationFile::KIND_CREATE_INDEX, $st[2]['kind']);
        self::assertTrue(MigrationFile::isAdditiveOnly($st));

        foreach (['ALTER TABLE t DROP COLUMN a', 'ALTER TABLE t ADD COLUMN b INT, MODIFY c INT', 'UPDATE t SET a = 1', 'DROP TABLE t', 'ALTER TABLE t ADD CONSTRAINT fk FOREIGN KEY (a) REFERENCES u (id)'] as $bad) {
            self::assertFalse(MigrationFile::isAdditiveOnly(MigrationFile::parse($bad)), $bad);
        }
        self::assertFalse(MigrationFile::isAdditiveOnly([]), 'fichier vide');
    }

    // ── iot_db_schema ──────────────────────────────────────────────────

    public function testDbSchemaListsTablesColumnsAndMigrationStates(): void
    {
        $server = $this->server();
        $tables = array_column(self::data($this->call($server, 'iot_db_schema', []))['tables'], 'name');
        self::assertContains('ffp3Data', $tables);

        $table = self::data($this->call($server, 'iot_db_schema', ['table' => 'ffp3Data']));
        self::assertContains('EauAquarium', array_column($table['columns'], 'name'));

        $list = self::data($this->call($server, 'iot_db_schema', ['migrations' => true]));
        $byFile = array_column($list['migrations'], null, 'file');
        self::assertSame(['2026_05_already.sql', '2026_07_drop.sql', '2026_11_new_col.sql'], array_keys($byFile), 'seuls les *.sql');
        self::assertSame('applied', $byFile['2026_05_already.sql']['status']);
        self::assertSame('unverifiable', $byFile['2026_07_drop.sql']['status']);
        self::assertSame('pending', $byFile['2026_11_new_col.sql']['status']);
        self::assertContains('ffp3Data.wlAquaStatus', $byFile['2026_11_new_col.sql']['missing']);

        $detail = self::data($this->call($server, 'iot_db_schema', ['migration' => '2026_11_new_col.sql']));
        self::assertSame('table_missing', $detail['statements'][2]['status'], 'ffp3DataS3 absente du schéma de test');
    }

    public function testDbSchemaIsAdminOnlyAndRefusesTraversal(): void
    {
        $server = $this->server();
        $r = $this->call($server, 'iot_db_schema', [], 'operator');
        self::assertTrue($r['isError']);
        self::assertSame('forbidden', self::data($r)['code']);
        $r = $this->call($server, 'iot_db_schema', ['migration' => '../config/x.sql']);
        self::assertTrue($r['isError'], 'motif de nom refusé par le schéma');
        $r = $this->call($server, 'iot_db_schema', ['migration' => 'absent.sql']);
        self::assertSame('migration_unknown', self::data($r)['code']);
    }

    // ── iot_sql_select ─────────────────────────────────────────────────

    public function testSqlSelectReadsWithCapsAndMasks(): void
    {
        $server = $this->server(['MCP_SQL_MAX_ROWS' => '5']);
        $data = self::data($this->call($server, 'iot_sql_select', ['sql' => 'SELECT id, EauAquarium, mail FROM ffp3Data ORDER BY id']));
        self::assertSame(5, $data['rowCount'], 'plafond serveur MCP_SQL_MAX_ROWS');
        self::assertTrue($data['truncated']);
        self::assertStringNotContainsString('secret@example.org', (string) json_encode($data), 'e-mails masqués');

        $users = self::data($this->call($server, 'iot_sql_select', ['sql' => 'SELECT * FROM n3_users']));
        self::assertSame('[masqué]', $users['rows'][0]['password_hash']);
    }

    public function testSqlSelectRefusesWritesSecretsAndNonAdmins(): void
    {
        $server = $this->server();
        foreach (['UPDATE ffp3Data SET EauAquarium = 1', 'DELETE FROM ffp3Data', 'DROP TABLE ffp3Data', 'SELECT 1; DELETE FROM ffp3Data'] as $sql) {
            $r = $this->call($server, 'iot_sql_select', ['sql' => $sql]);
            self::assertTrue($r['isError'], $sql);
        }
        $r = $this->call($server, 'iot_sql_select', ['sql' => 'SELECT password_hash FROM n3_users']);
        self::assertSame('sql_secret_column', self::data($r)['code']);
        self::assertSame(12, (int) $this->pdo->query('SELECT COUNT(*) FROM ffp3Data')->fetchColumn(), 'aucune écriture');

        $r = $this->call($server, 'iot_sql_select', ['sql' => 'SELECT 1'], 'operator');
        self::assertTrue($r['isError']);

        $off = $this->server(['MCP_SQL_SELECT_ENABLED' => 'false']);
        self::assertTrue($this->call($off, 'iot_sql_select', ['sql' => 'SELECT 1'])['isError'] ?? true, 'désactivable');
    }

    // ── iot_migrate ────────────────────────────────────────────────────

    public function testMigrateEnabledByDefaultAndDisableable(): void
    {
        $plan = self::data($this->call($this->server(), 'iot_migrate', ['file' => '2026_11_new_col.sql']));
        self::assertFalse($plan['executed'], '6.61.0 : actif par défaut, plan sans exécution');

        $off = $this->server(['MCP_MIGRATIONS_ENABLED' => 'false']);
        $r = $this->call($off, 'iot_migrate', ['file' => '2026_11_new_col.sql'], 'admin', ['iot.read', 'iot.control']);
        self::assertTrue($r['isError'] ?? true);
    }

    public function testMigratePlanAllThenApplyAllWithSingleCode(): void
    {
        file_put_contents($this->dir . '/2026_11_new_table.sql', "CREATE TABLE IF NOT EXISTS mcp_extra (id INTEGER PRIMARY KEY);\n");
        $server = $this->server();
        $plan = self::data($this->call($server, 'iot_migrate', ['action' => 'plan_all']));
        self::assertSame(['2026_11_new_col.sql', '2026_11_new_table.sql'], array_column($plan['pending_files'], 'file'));
        self::assertSame(3, $plan['to_execute']);
        self::assertFalse($plan['executed']);

        $scopes = ['iot.read', 'iot.control'];
        $pending = self::data($this->call($server, 'iot_migrate', ['action' => 'apply_all'], 'admin', $scopes));
        self::assertTrue($pending['confirmation_required']);
        self::assertSame(1, preg_match('/Code à usage unique[^\n]*: (\d{6})/', $this->sentBodies[0], $m));

        $done = self::data($this->call($server, 'iot_migrate', [
            'action' => 'apply_all', 'confirmation_id' => $pending['confirmation_id'], 'confirmation_code' => $m[1],
        ], 'admin', $scopes));
        self::assertTrue($done['ok']);
        self::assertSame([], $done['still_pending']);
        $schema = new SchemaReader($this->pdo);
        self::assertTrue($schema->columnExists('ffp3Data2', 'wlAquaStatus'));
        self::assertTrue($schema->tableExists('mcp_extra'));
        self::assertCount(1, $this->sentBodies, 'un seul code pour tout le lot');
    }

    public function testMigrateRequiresFileForSingleActions(): void
    {
        $r = $this->call($this->server(), 'iot_migrate', ['action' => 'plan']);
        self::assertSame('missing_file', self::data($r)['code']);
    }

    public function testMigratePlanThenConfirmedApplyThenIdempotentReplay(): void
    {
        $server = $this->server(['MCP_MIGRATIONS_ENABLED' => 'true']);
        $plan = self::data($this->call($server, 'iot_migrate', ['file' => '2026_11_new_col.sql']));
        self::assertFalse($plan['executed']);
        self::assertSame(2, $plan['to_execute'], 'ffp3Data et ffp3Data2 ; ffp3DataS3 absente');
        self::assertFalse((new SchemaReader($this->pdo))->columnExists('ffp3Data', 'wlAquaStatus'), 'le plan n\'exécute rien');

        try {
            $this->call($server, 'iot_migrate', ['file' => '2026_11_new_col.sql', 'action' => 'apply']);
            self::fail('apply exige iot.control');
        } catch (\App\Mcp\InsufficientScopeException) {
            // McpController la transforme en 403 + step-up OAuth (comme iot_sql).
        }

        $scopes = ['iot.read', 'iot.control'];
        $pending = self::data($this->call($server, 'iot_migrate', ['file' => '2026_11_new_col.sql', 'action' => 'apply'], 'admin', $scopes));
        self::assertTrue($pending['confirmation_required']);
        self::assertSame(1, preg_match('/Code à usage unique[^\n]*: (\d{6})/', $this->sentBodies[0], $m));

        $done = self::data($this->call($server, 'iot_migrate', [
            'file' => '2026_11_new_col.sql', 'action' => 'apply',
            'confirmation_id' => $pending['confirmation_id'], 'confirmation_code' => $m[1],
        ], 'admin', $scopes));
        self::assertTrue($done['ok']);
        self::assertSame([true, true, false], array_column($done['results'], 'executed'));
        self::assertSame('applied', $done['status_after']);
        $schema = new SchemaReader($this->pdo);
        self::assertTrue($schema->columnExists('ffp3Data', 'wlAquaStatus'));
        self::assertTrue($schema->columnExists('ffp3Data2', 'wlAquaStatus'));

        $again = self::data($this->call($server, 'iot_migrate', ['file' => '2026_11_new_col.sql', 'action' => 'apply'], 'admin', $scopes));
        self::assertFalse($again['executed'], 'rejouer est sans effet (aucun nouveau code demandé)');
        self::assertCount(1, $this->sentBodies);
    }

    public function testMigrateRefusesNonAdditiveFiles(): void
    {
        $server = $this->server(['MCP_MIGRATIONS_ENABLED' => 'true']);
        $r = $this->call($server, 'iot_migrate', ['file' => '2026_07_drop.sql', 'action' => 'apply'], 'admin', ['iot.read', 'iot.control']);
        self::assertSame('migration_not_additive', self::data($r)['code']);
        self::assertTrue((new SchemaReader($this->pdo))->tableExists('ffp3Data2'));
    }
}
