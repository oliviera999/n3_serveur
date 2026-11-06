<?php

declare(strict_types=1);

namespace Tests\Mcp;

use App\Config\TableConfig;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Support\ReadOnlyTransaction;
use App\Mcp\ToolRegistry;
use App\Mcp\Tools\LatestTool;
use App\Mcp\Tools\StatsTool;
use App\Repository\McpReadRepository;
use App\Service\LogService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

/**
 * Diagnostic d'écho (6.57.0) : visible dans iot_latest / iot_stats quand la migration
 * 2026_11_ffp3_echo_diag.sql est appliquée (ffp3Data ici), sans casser les outils sur une
 * table non migrée (ffp3Data2 = env test) — colonnes optionnelles du catalogue.
 */
final class EchoDiagExposureTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = McpSqliteFixture::pdo();
        McpSqliteFixture::seedFfp3($this->pdo);
        McpSqliteFixture::seedFfp3($this->pdo, 'ffp3Data2', 3);
        foreach (['wlAquaStatus TEXT', 'wlAquaTimeouts INTEGER', 'wlAquaRejected INTEGER', 'wlAquaRawMm INTEGER',
            'wlPotaStatus TEXT', 'wlPotaTimeouts INTEGER', 'wlPotaRejected INTEGER', 'wlPotaRawMm INTEGER'] as $col) {
            $this->pdo->exec('ALTER TABLE ffp3Data ADD COLUMN ' . $col);
        }
        $this->pdo->exec("UPDATE ffp3Data SET wlAquaStatus = 'no_echo', wlAquaTimeouts = 8, wlAquaRejected = 0, wlAquaRawMm = 0");
    }

    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function call(string $tool, array $args): array
    {
        $config = McpSqliteFixture::config(['MCP_ALLOWED_ENVS' => 'prod,test']);
        $read = new McpReadRepository($this->pdo);
        $server = new McpServer(
            new ToolRegistry([new LatestTool($config, $read), new StatsTool($config, $read)], $config),
            new ReadOnlyTransaction($this->pdo),
            $this->createMock(LogService::class),
            $config
        );
        $result = $server->handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]],
            new McpContext(1, 'reader', 'reader', ['iot.read'], 'c')
        )['result'];
        self::assertFalse($result['isError'], (string) ($result['content'][0]['text'] ?? ''));

        return json_decode($result['content'][0]['text'], true);
    }

    public function testDiagnosticVisibleOnMigratedTable(): void
    {
        $data = $this->call('iot_latest', ['family' => 'ffp3']);
        self::assertSame('no_echo', $data['values']['wlAquaStatus']['value']);
        self::assertSame(8.0, $data['values']['wlAquaTimeouts']['value']);
        self::assertSame('tirs', $data['values']['wlAquaTimeouts']['unit']);
        self::assertArrayNotHasKey('wlPotaStatus', $data['values'], 'colonne présente mais vide : omise');

        $stats = $this->call('iot_stats', ['family' => 'ffp3', 'fields' => ['wlAquaTimeouts']]);
        self::assertSame(8.0, $stats['fields']['wlAquaTimeouts']['max']);
    }

    public function testToolsStillWorkOnTableWithoutMigration(): void
    {
        $data = $this->call('iot_latest', ['family' => 'ffp3', 'env' => 'test']);
        self::assertArrayHasKey('EauAquarium', $data['values']);
        self::assertArrayNotHasKey('wlAquaStatus', $data['values']);

        $stats = $this->call('iot_stats', ['family' => 'ffp3', 'env' => 'test']);
        self::assertContains('wlAquaTimeouts', $stats['empty_fields']);
    }
}
