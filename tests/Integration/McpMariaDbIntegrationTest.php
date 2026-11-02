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
        self::assertSame(TableConfig::getDefaultEnvironment(), TableConfig::getEnvironment());
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
