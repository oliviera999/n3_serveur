<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\TableConfig;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Support\ActiveAlertsReader;
use App\Mcp\Support\ReadOnlyTransaction;
use App\Mcp\ToolRegistry;
use App\Mcp\Tools\LatestTool;
use App\Mcp\Tools\OverviewTool;
use App\Mcp\Tools\SeriesTool;
use App\Mcp\Tools\StatsTool;
use App\Mcp\Tools\TidesTool;
use App\OAuth\AuthorizationServer;
use App\OAuth\Pkce;
use App\Repository\McpOAuthRepository;
use App\Repository\McpReadRepository;
use App\Repository\OutputMonitorRepository;
use App\Repository\SensorReadRepository;
use App\Repository\UserRepository;
use App\Service\LogService;
use App\Service\OfflineThresholdResolver;
use App\Service\TideAnalysisService;
use App\Service\TideCycleDetector;
use PDO;
use PHPUnit\Framework\Attributes\BackupGlobals;
use Tests\Support\McpSqliteFixture;

/**
 * Intégration MariaDB/MySQL du connecteur MCP (schéma réel docker/mysql/init/*.sql).
 *
 * Prérequis : DB_HOST / DB_NAME / DB_USER / DB_PASS (+ DB_PORT) vers une base où les
 * scripts `docker/mysql/init/*.sql` ont été chargés (dont 96-mcp-oauth.sql). Ignoré
 * proprement sinon. Données de test isolées (sensor = 'mcp-it', client 'mcp-it-…').
 *
 * Vérifie ce que SQLite ne peut pas prouver : `SET SESSION TRANSACTION READ ONLY`
 * bloque réellement les écritures, l'agrégation `TIMESTAMPDIFF` par bucket, STDDEV_POP,
 * et le flux OAuth sur le vrai schéma (types, ENUM, longueurs de colonnes).
 */
#[BackupGlobals(false)]
final class McpMariaDbIntegrationTest extends IntegrationDbTestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->createPdoOrSkip();
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        try {
            $this->pdo->query('SELECT 1 FROM mcp_oauth_tokens LIMIT 1');
        } catch (\PDOException) {
            self::markTestSkipped('Tables MCP absentes : charger docker/mysql/init/96-mcp-oauth.sql.');
        }
        $this->cleanup();
        $stmt = $this->pdo->prepare("INSERT INTO ffp3Data (sensor, version, TempEau, EauAquarium, EauReserve, diffMaree, reading_time) VALUES ('mcp-it', '15.99', ?, ?, 400, ?, ?)");
        for ($i = 0; $i < 48; $i++) {
            $stmt->execute([24 + ($i % 3), 170 + ($i % 6) * 8, ($i % 2) ? 4 : -4, date('Y-m-d H:i:s', strtotime('2026-01-15 00:02:00') + $i * 900)]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->cleanup();
        }
        TableConfig::resetRequestEnvironment();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM ffp3Data WHERE sensor = 'mcp-it'");
        $this->pdo->exec("DELETE FROM mcp_oauth_tokens WHERE client_id LIKE 'mcp-it%'");
        $this->pdo->exec("DELETE FROM mcp_oauth_codes WHERE client_id LIKE 'mcp-it%'");
        $this->pdo->exec("DELETE FROM mcp_control_plans WHERE client_id LIKE 'mcp-it%'");
    }

    private function server(): McpServer
    {
        $config = McpSqliteFixture::config();
        $read = new McpReadRepository($this->pdo);
        $tools = [
            new OverviewTool($config, $read, new OfflineThresholdResolver(new OutputMonitorRepository($this->pdo)), new ActiveAlertsReader(sys_get_temp_dir() . '/mcp-it-none')),
            new LatestTool($config, $read),
            new SeriesTool($config, $read),
            new StatsTool($config, $read),
            new TidesTool($config, $read, new TideAnalysisService(new SensorReadRepository($this->pdo), new TideCycleDetector())),
        ];

        return new McpServer(new ToolRegistry($tools, $config), new ReadOnlyTransaction($this->pdo), $this->createMock(LogService::class), $config);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function call(string $tool, array $args): array
    {
        $r = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]], new McpContext(1, 'it', 'reader', ['iot.read'], 'mcp-it'));
        self::assertFalse($r['result']['isError'], $r['result']['content'][0]['text']);

        return json_decode($r['result']['content'][0]['text'], true);
    }

    public function testReadOnlySessionRejectsWritesOnRealServer(): void
    {
        $guard = new ReadOnlyTransaction($this->pdo);
        try {
            $guard->run(function (): void {
                $this->pdo->exec("INSERT INTO ffp3Data (sensor, reading_time) VALUES ('mcp-it-write', NOW())");
            });
            self::fail('écriture acceptée en transaction READ ONLY');
        } catch (\PDOException $e) {
            self::assertStringContainsString('READ ONLY', strtoupper($e->getMessage()));
        }
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM ffp3Data WHERE sensor = 'mcp-it-write'")->fetchColumn());
        // La session est rendue en lecture/écriture après coup (connexion partagée).
        $this->pdo->exec("UPDATE ffp3Data SET version = '15.99' WHERE sensor = 'mcp-it'");
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testSelectOnlyUserCannotWrite(): void
    {
        $user = 'mcp_it_ro';
        $db = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        try {
            $this->pdo->exec("CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY 'ro-pass-it'");
            $this->pdo->exec("CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY 'ro-pass-it'");
            $this->pdo->exec("GRANT SELECT ON `{$db}`.* TO '{$user}'@'%'");
            $this->pdo->exec("GRANT SELECT ON `{$db}`.* TO '{$user}'@'localhost'");
        } catch (\PDOException $e) {
            self::markTestSkipped('Création d\'utilisateur SQL impossible : ' . $e->getMessage());
        }
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT') ?: '3306', $db);
        $ro = new PDO($dsn, $user, 'ro-pass-it', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $latest = (new McpReadRepository($ro))->latest('ffp3');
        self::assertNotNull($latest);
        $this->expectException(\PDOException::class);
        $ro->exec("DELETE FROM ffp3Data WHERE sensor = 'mcp-it'");
    }

    public function testSeriesBucketsAndStddevOnMariaDb(): void
    {
        $envBefore = TableConfig::getEnvironment();
        $data = $this->call('iot_series', [
            'family' => 'ffp3', 'fields' => ['TempEau', 'EauAquarium'],
            'from' => '2026-01-15T00:00:00', 'to' => '2026-01-15T12:00:00', 'interval' => '1h',
        ]);
        self::assertSame(12, $data['points_count']);
        self::assertSame('2026-01-15 00:00:00', $data['points'][0]['t']);
        self::assertSame(4, $data['points'][0]['n']);
        self::assertSame(24.75, $data['points'][0]['TempEau'], 'moyenne de 24, 25, 26, 24');

        $stats = $this->call('iot_stats', ['family' => 'ffp3', 'from' => '2026-01-15', 'to' => '2026-01-16', 'fields' => ['EauAquarium']]);
        self::assertSame(48, $stats['readings']);
        self::assertSame(17.0, $stats['fields']['EauAquarium']['min']);
        self::assertSame(21.0, $stats['fields']['EauAquarium']['max']);
        self::assertGreaterThan(0.0, $stats['fields']['EauAquarium']['stddev']);

        $tides = $this->call('iot_tides', ['from' => '2026-01-15', 'to' => '2026-01-16']);
        self::assertSame(48, $tides['readings']);
        self::assertSame($envBefore, TableConfig::getEnvironment(), 'environnement de la requête restauré');
    }

    /**
     * Relecture sécurité PR #100 (B1), sur le VRAI schéma : un plan ffp3 en `n3pp_test`
     * (qui résout vers ffp3Outputs = PRODUCTION) est refusé et ffp3Outputs reste intact,
     * même si le couple est listé dans MCP_CONTROL_ALLOWED_TARGETS.
     */
    public function testControlPlanOnForeignTestEnvRefusedAndProductionUntouched(): void
    {
        $before = $this->pdo->query('SELECT gpio, state, requestTime, lastModifiedBy FROM ffp3Outputs ORDER BY id')->fetchAll();
        if ($before === []) {
            self::markTestSkipped('ffp3Outputs vide (charger docker/mysql/init/10-seed.sql).');
        }
        $config = McpSqliteFixture::config(['MCP_CONTROL_ENABLED' => 'true', 'MCP_CONTROL_ALLOWED_TARGETS' => 'ffp3:n3pp_test,ffp3:msp_test,ffp3:energie_test']);
        $plan = new \App\Mcp\Tools\PlanOutputChangeTool($config, new McpReadRepository($this->pdo), new McpOAuthRepository($this->pdo));
        $server = new McpServer(new ToolRegistry([$plan], $config), new ReadOnlyTransaction($this->pdo), $this->createMock(LogService::class), $config);
        $ctx = new McpContext(1, 'it', 'operator', ['iot.read', 'iot.control'], 'mcp-it');
        foreach (['n3pp_test', 'msp_test', 'energie_test'] as $env) {
            $r = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
                'name' => 'iot_plan_output_change', 'arguments' => ['family' => 'ffp3', 'gpio' => 2, 'value' => 1, 'env' => $env],
            ]], $ctx);
            self::assertTrue($r['result']['isError'], $env);
            self::assertSame('production_target_refused', json_decode($r['result']['content'][0]['text'], true)['code'], $env);
        }
        self::assertSame($before, $this->pdo->query('SELECT gpio, state, requestTime, lastModifiedBy FROM ffp3Outputs ORDER BY id')->fetchAll());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM mcp_control_plans WHERE client_id = 'mcp-it'")->fetchColumn());
    }

    /**
     * Plan → apply réel sur ffp3:test (ffp3Outputs2) : verrou `SELECT … FOR UPDATE` dans une
     * transaction MariaDB, écriture limitée à la table de test, production intacte.
     */
    public function testApplyOnRealTestTableWithRowLock(): void
    {
        $gpio15 = $this->pdo->query("SELECT state FROM ffp3Outputs2 WHERE gpio = 15 AND name IS NOT NULL AND name <> '' LIMIT 1")->fetchColumn();
        if ($gpio15 === false) {
            self::markTestSkipped('ffp3Outputs2 sans GPIO 15.');
        }
        $prod = $this->pdo->query('SELECT gpio, state FROM ffp3Outputs ORDER BY id')->fetchAll();
        $this->pdo->exec("UPDATE ffp3Outputs2 SET state = '0' WHERE gpio = 15");
        $config = McpSqliteFixture::config(['MCP_CONTROL_ENABLED' => 'true']);
        $store = new McpOAuthRepository($this->pdo);
        $boards = new \App\Repository\BoardRepository($this->pdo);
        $writer = new \App\Mcp\Control\OutputWriter(
            new \App\Service\OutputService(new \App\Repository\OutputRepository($this->pdo), $boards, new SensorReadRepository($this->pdo)),
            new \App\Repository\OutputRepository($this->pdo),
            new \App\Repository\N3ppOutputRepository($this->pdo, $boards),
            new \App\Repository\MspOutputRepository($this->pdo, $boards)
        );
        $audit = $this->createMock(\App\Service\ControlAuditLogger::class);
        $executor = new \App\Mcp\Control\ControlExecutor($config, $store, $writer, $audit);
        $confirm = new \App\Mcp\Confirm\ConfirmationGate(
            $config,
            new \App\Repository\McpConfirmRepository($this->pdo),
            $this->createConfiguredMock(\App\Service\NotificationService::class, [
                'recipient' => 'notif@example.test',
                'sendConfirmationCode' => true,
            ]),
            new UserRepository($this->pdo),
            $audit,
            $this->createMock(LogService::class),
        );
        $tools = [
            new \App\Mcp\Tools\PlanOutputChangeTool($config, new McpReadRepository($this->pdo), $store),
            new \App\Mcp\Tools\ApplyPlanTool($config, $store, $executor, $confirm),
        ];
        $server = new McpServer(new ToolRegistry($tools, $config), new ReadOnlyTransaction($this->pdo), $this->createMock(LogService::class), $config);
        $ctx = new McpContext(1, 'it', 'operator', ['iot.read', 'iot.control'], 'mcp-it');
        $call = static fn (string $tool, array $args): array => json_decode($server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]], $ctx)['result']['content'][0]['text'], true);
        try {
            $plan = $call('iot_plan_output_change', ['family' => 'ffp3', 'gpio' => 15, 'value' => 1, 'env' => 'test']);
            self::assertSame('ffp3Outputs2', $plan['target_table']);
            $applied = $call('iot_apply_plan', ['plan_id' => $plan['plan_id']]);
            self::assertTrue($applied['applied'] ?? false, json_encode($applied));
            self::assertSame('1', (string) $this->pdo->query('SELECT state FROM ffp3Outputs2 WHERE gpio = 15 ORDER BY id LIMIT 1')->fetchColumn());
            self::assertSame($prod, $this->pdo->query('SELECT gpio, state FROM ffp3Outputs ORDER BY id')->fetchAll(), 'production intacte');
            self::assertFalse($this->pdo->inTransaction());
        } finally {
            $this->pdo->exec('UPDATE ffp3Outputs2 SET state = ' . $this->pdo->quote((string) $gpio15) . ' WHERE gpio = 15');
        }
    }

    public function testPasswordChangeRevokesTokensOnRealSchema(): void
    {
        $userId = (int) $this->pdo->query('SELECT id FROM n3_users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($userId === 0) {
            self::markTestSkipped('Aucun compte n3_users actif.');
        }
        $users = new UserRepository($this->pdo);
        $originalHash = (string) $this->pdo->query("SELECT password_hash FROM n3_users WHERE id = {$userId}")->fetchColumn();
        $server = new AuthorizationServer(new McpOAuthRepository($this->pdo), $users, McpSqliteFixture::config());
        $verifier = str_repeat('w', 50);
        $code = $server->issueCode('mcp-it-pwd', $userId, 'https://claude.ai/api/mcp/auth_callback', ['iot.read'], null, Pkce::challengeFor($verifier));
        $tokens = $server->exchangeCode($code, 'mcp-it-pwd', 'https://claude.ai/api/mcp/auth_callback', $verifier, null, 'https://iot.example.test/mcp');
        self::assertNotNull($server->validateAccessToken($tokens['access_token'], 'https://iot.example.test/mcp'));
        try {
            $users->updatePassword($userId, password_hash('nouveau-' . bin2hex(random_bytes(4)), PASSWORD_BCRYPT, ['cost' => 4]));
            self::assertNull($server->validateAccessToken($tokens['access_token'], 'https://iot.example.test/mcp'));
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM mcp_oauth_tokens WHERE client_id = 'mcp-it-pwd' AND revoked_at IS NULL")->fetchColumn());
        } finally {
            $this->pdo->prepare('UPDATE n3_users SET password_hash = ? WHERE id = ?')->execute([$originalHash, $userId]);
        }
    }

    public function testCollationVariantsResolveToSameAccountOnRealSchema(): void
    {
        $row = $this->pdo->query("SELECT id, username FROM n3_users WHERE username REGEXP '^[a-z]+$' ORDER BY id LIMIT 1")->fetch();
        if (!is_array($row)) {
            self::markTestSkipped('Aucun compte n3_users à nom ASCII simple.');
        }
        $id = (int) $row['id'];
        $name = (string) $row['username'];
        $users = new UserRepository($this->pdo);
        $accented = ['a' => 'á', 'e' => 'é', 'i' => 'î', 'o' => 'ô', 'u' => 'ù'];
        $variants = [
            strtoupper($name),
            $name . ' ',
            strtr($name, $accented),
            mb_strtoupper(strtr($name, $accented)),
            mb_substr($name, 0, 1) . "\u{0301}" . mb_substr($name, 1),
        ];
        foreach ($variants as $variant) {
            // La base (utf8mb4_unicode_ci) ramène la variante au compte : la clé `id:` du
            // compteur d'échecs est donc commune, et la normalisation donne la même clé `name:`.
            self::assertSame($id, $users->findIdByUsername($variant), json_encode($variant, JSON_THROW_ON_ERROR));
            self::assertSame(\App\Controller\OAuth\OAuthController::normalizeUsername($name), \App\Controller\OAuth\OAuthController::normalizeUsername($variant));
        }
        self::assertNull($users->findIdByUsername($name . 'x-inexistant'));
    }

    public function testOAuthFlowOnRealSchema(): void
    {
        $userId = (int) $this->pdo->query('SELECT id FROM n3_users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($userId === 0) {
            self::markTestSkipped('Aucun compte n3_users actif.');
        }
        $server = new AuthorizationServer(new McpOAuthRepository($this->pdo), new UserRepository($this->pdo), McpSqliteFixture::config());
        $verifier = str_repeat('v', 50);
        $code = $server->issueCode('mcp-it-client', $userId, 'https://claude.ai/api/mcp/auth_callback', ['iot.read', 'offline_access'], null, Pkce::challengeFor($verifier));
        $t0 = microtime(true);
        $tokens = $server->exchangeCode($code, 'mcp-it-client', 'https://claude.ai/api/mcp/auth_callback', $verifier, null, 'https://iot.example.test/mcp');
        self::assertLessThan(1.0, microtime(true) - $t0, '/token < 1 s');
        $rotated = $server->refresh($tokens['refresh_token'], 'mcp-it-client', null, 'https://iot.example.test/mcp');
        self::assertNotNull($server->validateAccessToken($rotated['access_token'], 'https://iot.example.test/mcp'));
        // Restent actifs : les deux jetons d'accès (expiration naturelle) et le NOUVEAU refresh ;
        // l'ancien refresh est révoqué par la rotation.
        $active = $this->pdo->query("SELECT token_type, COUNT(*) AS n FROM mcp_oauth_tokens WHERE client_id = 'mcp-it-client' AND revoked_at IS NULL GROUP BY token_type")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(['access' => 2, 'refresh' => 1], array_map('intval', $active));
    }
}
