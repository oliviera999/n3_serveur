<?php

declare(strict_types=1);

namespace Tests\Mcp\Productivity;

use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Support\ReadOnlyTransaction;
use App\Mcp\Tool;
use App\Mcp\ToolRegistry;
use App\Mcp\ToolResult;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

/**
 * Visibilité des outils selon les drapeaux .env et le rôle (stubs, sans SQLite).
 */
final class FlagsAndRolesTest extends TestCase
{
    private function server(McpConfig $config, array $tools): McpServer
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            // ReadOnlyTransaction n'ouvre une TX que sur MySQL ; un PDO factice suffit sinon.
            $pdo = $this->createMock(\PDO::class);
            $pdo->method('inTransaction')->willReturn(true);
        } else {
            $pdo = new \PDO('sqlite::memory:');
        }

        return new McpServer(
            new ToolRegistry($tools, $config),
            new ReadOnlyTransaction($pdo),
            $this->createMock(LogService::class),
            $config,
        );
    }

    private function ctx(string $role, array $scopes, bool $privacy = false): McpContext
    {
        $ids = ['reader' => 1, 'operator' => 2, 'admin' => 3];

        return new McpContext($ids[$role], $role, $role, $scopes, 'c', null, $privacy, $role . '@example.test');
    }

    private function stub(
        string $name,
        McpConfig $config,
        string $scope,
        callable $available,
    ): Tool {
        return new class ($name, $config, $scope, $available) implements Tool {
            public function __construct(
                private string $n,
                private McpConfig $config,
                private string $scope,
                private $available,
            ) {
            }

            public function name(): string
            {
                return $this->n;
            }

            public function title(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return $this->n;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
            }

            public function annotations(): array
            {
                return [];
            }

            public function isReadOnly(): bool
            {
                return true;
            }

            public function requiredScope(): string
            {
                return $this->scope;
            }

            public function isAvailableFor(McpContext $context): bool
            {
                return ($this->available)($context, $this->config);
            }

            public function call(array $arguments, McpContext $context): ToolResult
            {
                return ToolResult::json(['ok' => true]);
            }
        };
    }

    public function testSetOutputHiddenWhenRequirePlanTrue(): void
    {
        $config = McpSqliteFixture::config([
            'MCP_CONTROL_ENABLED' => 'true',
            'MCP_CONTROL_REQUIRE_PLAN' => 'true',
        ]);
        $tool = $this->stub(
            'iot_set_output',
            $config,
            McpConfig::SCOPE_CONTROL,
            static fn (McpContext $c, McpConfig $cfg): bool => $cfg->isControlEnabled()
                && !$cfg->requirePlan()
                && $c->isAdmin()
        );
        $server = $this->server($config, [$tool]);
        $list = $server->handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            $this->ctx('admin', ['iot.read', 'iot.control'])
        );
        self::assertNotContains('iot_set_output', array_column($list['result']['tools'], 'name'));
    }

    public function testSetOutputVisibleWhenRequirePlanFalseForAdmin(): void
    {
        $config = McpSqliteFixture::config([
            'MCP_CONTROL_ENABLED' => 'true',
            'MCP_CONTROL_REQUIRE_PLAN' => 'false',
        ]);
        $tool = $this->stub(
            'iot_set_output',
            $config,
            McpConfig::SCOPE_CONTROL,
            static fn (McpContext $c, McpConfig $cfg): bool => $cfg->isControlEnabled()
                && !$cfg->requirePlan()
                && $c->isAdmin()
        );
        $server = $this->server($config, [$tool]);
        $adminList = $server->handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            $this->ctx('admin', ['iot.read', 'iot.control'])
        );
        self::assertContains('iot_set_output', array_column($adminList['result']['tools'], 'name'));
        $opList = $server->handle(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            $this->ctx('operator', ['iot.read', 'iot.control'])
        );
        self::assertNotContains('iot_set_output', array_column($opList['result']['tools'], 'name'));
    }

    public function testSqlHiddenByDefault(): void
    {
        $config = McpSqliteFixture::config(['MCP_SQL_ENABLED' => 'false', 'MCP_MIGRATIONS_ENABLED' => 'false']);
        self::assertFalse($config->isSqlEnabled());
        self::assertFalse($config->isControlScopeOffered());
    }

    public function testMigrationsEnabledByDefaultOfferControlScope(): void
    {
        $config = McpSqliteFixture::config();
        self::assertTrue($config->isMigrationsEnabled(), '6.61.0 : iot_migrate actif par défaut');
        self::assertTrue($config->isControlScopeOffered());
    }

    public function testSqlEnablesControlScope(): void
    {
        $config = McpSqliteFixture::config(['MCP_SQL_ENABLED' => 'true']);
        self::assertTrue($config->isControlScopeOffered());
        self::assertContains(McpConfig::SCOPE_CONTROL, $config->resourceScopes());
    }

    public function testPrivacyDefaultsFalse(): void
    {
        $config = McpSqliteFixture::config();
        self::assertFalse($config->isPrivacyBypassEnabled());
        self::assertTrue($config->requirePlan());
        self::assertTrue($config->bioSafeguardsEnabled());
        self::assertFalse($config->isDeviceActionsEnabled());
        self::assertSame(0, $config->confirmUnlockMinutes());
    }

    public function testAccountsRequiresPrivacyAndAdmin(): void
    {
        $config = McpSqliteFixture::config(['MCP_PRIVACY_BYPASS' => 'true']);
        $tool = $this->stub(
            'iot_accounts',
            $config,
            McpConfig::SCOPE_READ,
            static fn (McpContext $c, McpConfig $cfg): bool => $cfg->isPrivacyBypassEnabled() && $c->isAdmin()
        );
        $server = $this->server($config, [$tool]);
        $admin = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->ctx('admin', ['iot.read'], true));
        self::assertContains('iot_accounts', array_column($admin['result']['tools'], 'name'));
        $op = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $this->ctx('operator', ['iot.read']));
        self::assertNotContains('iot_accounts', array_column($op['result']['tools'], 'name'));
    }

    public function testConfirmUnlockHiddenWhenMinutesZero(): void
    {
        $config = McpSqliteFixture::config(['MCP_CONFIRM_UNLOCK_MINUTES' => '0']);
        $tool = $this->stub(
            'iot_confirm_unlock',
            $config,
            McpConfig::SCOPE_READ,
            static fn (McpContext $c, McpConfig $cfg): bool => $cfg->confirmUnlockMinutes() > 0 && $c->isAdmin()
        );
        $server = $this->server($config, [$tool]);
        $list = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->ctx('admin', ['iot.read']));
        self::assertNotContains('iot_confirm_unlock', array_column($list['result']['tools'], 'name'));
    }

    public function testHiddenToolCallReturnsForbidden(): void
    {
        $config = McpSqliteFixture::config(['MCP_SQL_ENABLED' => 'false']);
        $tool = $this->stub('iot_sql', $config, McpConfig::SCOPE_READ, static fn (): bool => false);
        $server = $this->server($config, [$tool]);
        $r = $server->handle([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'iot_sql', 'arguments' => []],
        ], $this->ctx('admin', ['iot.read', 'iot.control']));
        self::assertTrue($r['result']['isError']);
        self::assertStringContainsString('forbidden', (string) json_encode($r['result']));
    }
}
