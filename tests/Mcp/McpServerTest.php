<?php

declare(strict_types=1);

namespace Tests\Mcp;

use App\Config\TableConfig;
use App\Mcp\InsufficientScopeException;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Support\ReadOnlyTransaction;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Mcp\ToolRegistry;
use App\Mcp\ToolResult;
use App\Mcp\Tools\AbstractTool;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

/**
 * Noyau JSON-RPC 2.0 du serveur MCP : négociation de version, notifications, batch,
 * erreurs standard, tools/list filtré, tools/call (validation, isError, scope, env).
 */
final class McpServerTest extends TestCase
{
    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
    }

    private static function context(string $role = 'reader', array $scopes = ['iot.read']): McpContext
    {
        return new McpContext(1, 'alice', $role, $scopes, 'client-test');
    }

    /**
     * @param list<Tool> $tools
     */
    private function server(array $tools, ?McpConfig $config = null): McpServer
    {
        $config ??= McpSqliteFixture::config();
        $logger = $this->createMock(LogService::class);

        return new McpServer(new ToolRegistry($tools, $config), new ReadOnlyTransaction(McpSqliteFixture::pdo()), $logger, $config);
    }

    private static function echoTool(McpConfig $config, string $scope = McpConfig::SCOPE_READ, ?\Closure $body = null): Tool
    {
        return new class ($config, $scope, $body) extends AbstractTool {
            public function __construct(McpConfig $config, private string $scope, private ?\Closure $body)
            {
                parent::__construct($config);
            }

            public function name(): string
            {
                return $this->scope === McpConfig::SCOPE_CONTROL ? 'iot_control_test' : 'iot_echo';
            }

            public function title(): string
            {
                return 'Écho';
            }

            public function description(): string
            {
                return 'Renvoie l\'environnement courant.';
            }

            public function requiredScope(): string
            {
                return $this->scope;
            }

            public function inputSchema(): array
            {
                return $this->objectSchema([
                    'env' => $this->envProperty($this->scope === McpConfig::SCOPE_CONTROL),
                    'n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 2],
                ]);
            }

            public function call(array $arguments, McpContext $context): ToolResult
            {
                if ($this->body !== null) {
                    return ($this->body)($arguments);
                }

                return $this->inEnv($arguments, static fn (string $env): array => [
                    'seen_env' => TableConfig::getEnvironment(),
                    'n' => $arguments['n'],
                    'table' => TableConfig::getDataTable(),
                ]);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeText(array $response): array
    {
        return json_decode($response['result']['content'][0]['text'], true);
    }

    public function testInitializeNegotiatesRequestedSupportedVersion(): void
    {
        $server = $this->server([]);
        foreach (['2025-06-18', '2025-03-26', '2025-11-25'] as $version) {
            $r = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => $version]], self::context());
            self::assertSame($version, $r['result']['protocolVersion']);
            self::assertArrayHasKey('tools', $r['result']['capabilities']);
            self::assertSame(McpServer::SERVER_NAME, $r['result']['serverInfo']['name']);
        }
    }

    public function testInitializeFallsBackToLatestForUnknownVersion(): void
    {
        $r = $this->server([])->handle(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']], self::context());
        self::assertSame('2025-11-25', $r['result']['protocolVersion']);
        self::assertSame('a', $r['id']);
    }

    public function testNotificationsProduceNoResponse(): void
    {
        $server = $this->server([]);
        self::assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], self::context()));
        self::assertNull($server->handle([['jsonrpc' => '2.0', 'method' => 'notifications/initialized']], self::context()));
    }

    public function testPingAndBatch(): void
    {
        $r = $this->server([])->handle([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'nope'],
        ], self::context());
        self::assertCount(2, $r);
        self::assertEquals(new \stdClass(), $r[0]['result']);
        self::assertSame(-32601, $r[1]['error']['code']);
    }

    public function testBatchLargerThanTwentyIsRejected(): void
    {
        $server = $this->server([]);
        $ok = $server->handle(array_fill(0, 20, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']), self::context());
        self::assertCount(20, $ok);
        $tooMany = $server->handle(array_fill(0, 21, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']), self::context());
        self::assertSame(-32600, $tooMany['error']['code']);
        self::assertNull($tooMany['id']);
    }

    public function testInvalidRequestErrors(): void
    {
        $server = $this->server([]);
        self::assertSame(-32600, $server->handle(['id' => 1, 'method' => 'ping'], self::context())['error']['code']);
        self::assertSame(-32600, $server->handle(['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'ping'], self::context())['error']['code']);
        self::assertSame(-32602, $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'inconnu']], self::context())['error']['code']);
        self::assertSame(-32602, $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => []], self::context())['error']['code']);
    }

    public function testToolsListHidesControlToolsByDefault(): void
    {
        $config = McpSqliteFixture::config();
        $server = $this->server([self::echoTool($config), self::echoTool($config, McpConfig::SCOPE_CONTROL)], $config);
        $r = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], self::context('admin', ['iot.read', 'iot.control']));
        self::assertSame(['iot_echo'], array_column($r['result']['tools'], 'name'));
        $def = $r['result']['tools'][0];
        self::assertSame(false, $def['inputSchema']['additionalProperties']);
        self::assertTrue($def['annotations']['readOnlyHint']);
    }

    public function testToolsListShowsControlToolsWhenEnabledForOperatorWithScope(): void
    {
        $config = McpSqliteFixture::config(['MCP_CONTROL_ENABLED' => 'true']);
        $server = $this->server([self::echoTool($config), self::echoTool($config, McpConfig::SCOPE_CONTROL)], $config);
        $names = static fn (array $r): array => array_column($r['result']['tools'], 'name');
        $list = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
        self::assertSame(['iot_echo', 'iot_control_test'], $names($server->handle($list, self::context('operator', ['iot.read', 'iot.control']))));
        self::assertSame(['iot_echo'], $names($server->handle($list, self::context('reader', ['iot.read', 'iot.control']))));
        // Sans scope iot.control : l'outil reste listé (le call déclenche le step-up OAuth).
        self::assertSame(['iot_echo', 'iot_control_test'], $names($server->handle($list, self::context('operator', ['iot.read']))));
    }

    public function testControlToolRefusedWhenDisabled(): void
    {
        $config = McpSqliteFixture::config();
        $server = $this->server([self::echoTool($config, McpConfig::SCOPE_CONTROL)], $config);
        $r = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'iot_control_test', 'arguments' => []]], self::context('admin', ['iot.read', 'iot.control']));
        self::assertTrue($r['result']['isError']);
        self::assertSame('forbidden', self::decodeText($r)['code']);
    }

    public function testControlToolWithoutScopeTriggersStepUp(): void
    {
        $config = McpSqliteFixture::config(['MCP_CONTROL_ENABLED' => 'true']);
        $server = $this->server([self::echoTool($config, McpConfig::SCOPE_CONTROL)], $config);
        $this->expectException(InsufficientScopeException::class);
        $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'iot_control_test', 'arguments' => []]], self::context('operator', ['iot.read']));
    }

    public function testCallValidatesArgumentsAndAppliesDefaults(): void
    {
        $config = McpSqliteFixture::config();
        $server = $this->server([self::echoTool($config)], $config);
        $ok = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'iot_echo']], self::context());
        self::assertFalse($ok['result']['isError']);
        $data = self::decodeText($ok);
        self::assertSame(2, $data['n']);
        self::assertSame('prod', $data['env']);
        self::assertTrue($data['is_production']);
        self::assertSame($data, $ok['result']['structuredContent']);

        foreach ([['n' => 9], ['x' => 1], ['env' => 'test'], ['n' => 'deux']] as $bad) {
            $r = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'iot_echo', 'arguments' => $bad]], self::context());
            self::assertTrue($r['result']['isError'], json_encode($bad));
            self::assertSame('invalid_arguments', self::decodeText($r)['code']);
        }
    }

    public function testEnvironmentSwitchIsScopedAndAlwaysReset(): void
    {
        $config = McpSqliteFixture::config(['MCP_ALLOWED_ENVS' => 'prod,s3,test']);
        $server = $this->server([self::echoTool($config)], $config);
        $r = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'iot_echo', 'arguments' => ['env' => 'test']]], self::context());
        $data = self::decodeText($r);
        self::assertSame('test', $data['seen_env']);
        self::assertSame('ffp3Data2', $data['table']);
        self::assertFalse($data['is_production']);
        self::assertSame(TableConfig::getDefaultEnvironment(), TableConfig::getEnvironment(), 'environnement remis à zéro après l\'appel');

        $s3 = self::decodeText($server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'iot_echo', 'arguments' => ['env' => 's3']]], self::context()));
        self::assertTrue($s3['is_production'], 's3 est de la PRODUCTION');
    }

    public function testEnvironmentResetEvenWhenToolThrows(): void
    {
        $config = McpSqliteFixture::config(['MCP_ALLOWED_ENVS' => 'prod,test']);
        $tool = new class ($config) extends AbstractTool {
            public function name(): string
            {
                return 'iot_boom';
            }

            public function title(): string
            {
                return 'Boom';
            }

            public function description(): string
            {
                return 'Lève une exception.';
            }

            public function inputSchema(): array
            {
                return $this->objectSchema(['env' => $this->envProperty()]);
            }

            public function call(array $arguments, McpContext $context): ToolResult
            {
                return $this->inEnv($arguments, static function (): array {
                    throw new \RuntimeException('secret interne : DB_PASS=hunter2');
                });
            }
        };
        $r = $this->server([$tool], $config)->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'iot_boom', 'arguments' => ['env' => 'test']]], self::context());
        self::assertSame(TableConfig::getDefaultEnvironment(), TableConfig::getEnvironment());
        self::assertTrue($r['result']['isError']);
        $text = $r['result']['content'][0]['text'];
        self::assertStringNotContainsString('hunter2', $text, 'aucun détail d\'exception renvoyé au modèle');
        self::assertStringContainsString('internal_error', $text);
    }

    public function testToolExceptionBecomesIsErrorResult(): void
    {
        $config = McpSqliteFixture::config();
        $tool = self::echoTool($config, McpConfig::SCOPE_READ, static function (): ToolResult {
            throw new ToolException('Fenêtre trop large', 'window_too_large');
        });
        $r = $this->server([$tool], $config)->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'iot_echo']], self::context());
        self::assertTrue($r['result']['isError']);
        self::assertSame(['error' => 'Fenêtre trop large', 'code' => 'window_too_large'], self::decodeText($r));
    }

    public function testDuplicateToolNamesRejected(): void
    {
        $config = McpSqliteFixture::config();
        $this->expectException(\LogicException::class);
        new ToolRegistry([self::echoTool($config), self::echoTool($config)], $config);
    }
}
