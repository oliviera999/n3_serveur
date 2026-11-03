<?php

declare(strict_types=1);

namespace Tests\Mcp\Productivity;

use App\Mcp\Confirm\ArgsFingerprint;
use App\Mcp\Confirm\ConfirmationGate;
use App\Mcp\Confirm\ConfirmCategory;
use App\Mcp\Confirm\ConfirmCodeHasher;
use App\Mcp\McpContext;
use App\Mcp\ToolException;
use App\Repository\McpConfirmRepository;
use App\Repository\UserRepository;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

final class ConfirmationGateTest extends TestCase
{
    private \PDO $pdo;
    private McpConfirmRepository $repo;
    private ConfirmationGate $gate;
    /** @var list<string> */
    private array $sentBodies = [];

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Extension PDO SQLite absente.');
        }
        $this->pdo = McpSqliteFixture::pdo();
        $this->repo = new McpConfirmRepository($this->pdo);
        $notifier = $this->createMock(NotificationService::class);
        $notifier->method('recipient')->willReturn('fallback@example.test');
        $notifier->method('sendConfirmationCode')->willReturnCallback(
            function (string $to, string $subject, string $body): bool {
                $this->sentBodies[] = $body;

                return true;
            }
        );
        $logger = $this->createMock(LogService::class);
        $audit = $this->createMock(ControlAuditLogger::class);
        $this->gate = new ConfirmationGate(
            McpSqliteFixture::config(),
            $this->repo,
            $notifier,
            new UserRepository($this->pdo),
            $audit,
            $logger,
        );
    }

    private function adminCtx(): McpContext
    {
        return new McpContext(3, 'admin', 'admin', ['iot.read', 'iot.control'], 'client-test', null, false, 'admin@example.test');
    }

    public function testIssuesCodeWithoutExecuting(): void
    {
        $result = $this->gate->check('iot_sql', ['sql' => 'DROP TABLE x'], $this->adminCtx(), [ConfirmCategory::SQL_DDL], [
            'cible' => 'DDL',
            'effet' => 'drop',
            'risques' => ['irrécupérable'],
        ]);
        self::assertNotNull($result);
        $data = $result->structured();
        self::assertTrue($data['confirmation_required'] ?? false);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $data['confirmation_id']);
        self::assertArrayNotHasKey('confirmation_code', $data);
        self::assertDoesNotMatchRegularExpression('/\b\d{6}\b/', (string) json_encode($data));
        self::assertCount(1, $this->sentBodies);
        self::assertMatchesRegularExpression('/Code à usage unique[^\\n]*: (\\d{6})/', $this->sentBodies[0]);
    }

    public function testBadCodeIncrementsAttempts(): void
    {
        $ctx = $this->adminCtx();
        $issued = $this->gate->check('iot_sql', ['sql' => 'DROP TABLE x'], $ctx, [ConfirmCategory::SQL_DDL], [
            'cible' => 't', 'effet' => 'e', 'risques' => [],
        ]);
        $id = (string) $issued->structured()['confirmation_id'];
        try {
            $this->gate->check('iot_sql', [
                'sql' => 'DROP TABLE x',
                'confirmation_id' => $id,
                'confirmation_code' => '000000',
            ], $ctx, [ConfirmCategory::SQL_DDL], ['cible' => 't', 'effet' => 'e', 'risques' => []]);
            self::fail('expected exception');
        } catch (ToolException $e) {
            self::assertSame('confirm_bad_code', $e->errorCode());
        }
        $row = $this->repo->findConfirmation($id);
        self::assertSame(1, (int) $row['attempts']);
    }

    public function testArgsChangedRejected(): void
    {
        $ctx = $this->adminCtx();
        $issued = $this->gate->check('iot_sql', ['sql' => 'DROP TABLE x'], $ctx, [ConfirmCategory::SQL_DDL], [
            'cible' => 't', 'effet' => 'e', 'risques' => [],
        ]);
        $id = (string) $issued->structured()['confirmation_id'];
        self::assertSame(1, preg_match('/Code à usage unique[^\\n]*: (\\d{6})/', $this->sentBodies[0], $m));
        $this->expectException(ToolException::class);
        $this->gate->check('iot_sql', [
            'sql' => 'DROP TABLE y',
            'confirmation_id' => $id,
            'confirmation_code' => $m[1],
        ], $ctx, [ConfirmCategory::SQL_DDL], ['cible' => 't', 'effet' => 'e', 'risques' => []]);
    }

    public function testValidCodeAllowsContinueAndCannotReuse(): void
    {
        $ctx = $this->adminCtx();
        $args = ['sql' => 'DROP TABLE x'];
        $issued = $this->gate->check('iot_sql', $args, $ctx, [ConfirmCategory::SQL_DDL], [
            'cible' => 't', 'effet' => 'e', 'risques' => [],
        ]);
        $id = (string) $issued->structured()['confirmation_id'];
        self::assertSame(1, preg_match('/Code à usage unique[^\\n]*: (\\d{6})/', $this->sentBodies[0], $m));
        $code = $m[1];
        $ok = $this->gate->check('iot_sql', $args + [
            'confirmation_id' => $id,
            'confirmation_code' => $code,
        ], $ctx, [ConfirmCategory::SQL_DDL], ['cible' => 't', 'effet' => 'e', 'risques' => []]);
        self::assertNull($ok);
        $this->expectException(ToolException::class);
        $this->gate->check('iot_sql', $args + [
            'confirmation_id' => $id,
            'confirmation_code' => $code,
        ], $ctx, [ConfirmCategory::SQL_DDL], ['cible' => 't', 'effet' => 'e', 'risques' => []]);
    }

    public function testConfirmSkipBypasses(): void
    {
        $gate = new ConfirmationGate(
            McpSqliteFixture::config(['MCP_CONFIRM_SKIP' => 'sql_ddl']),
            $this->repo,
            $this->createMock(NotificationService::class),
            new UserRepository($this->pdo),
            $this->createMock(ControlAuditLogger::class),
            $this->createMock(LogService::class),
        );
        $result = $gate->check('iot_sql', ['sql' => 'DROP TABLE x'], $this->adminCtx(), [ConfirmCategory::SQL_DDL], [
            'cible' => 't', 'effet' => 'e', 'risques' => [],
        ]);
        self::assertNull($result);
        self::assertSame([], $this->sentBodies);
    }

    public function testUnlockNeverSkippedByAll(): void
    {
        $gate = new ConfirmationGate(
            McpSqliteFixture::config(['MCP_CONFIRM_SKIP' => 'all']),
            $this->repo,
            $this->createConfiguredMock(NotificationService::class, [
                'recipient' => 'fallback@example.test',
                'sendConfirmationCode' => true,
            ]),
            new UserRepository($this->pdo),
            $this->createMock(ControlAuditLogger::class),
            $this->createMock(LogService::class),
        );
        // unlock seul → pas skippable
        $result = $gate->check('iot_confirm_unlock', ['reason' => 'fenêtre de travail'], $this->adminCtx(), [ConfirmCategory::UNLOCK], [
            'cible' => 't', 'effet' => 'e', 'risques' => [],
        ]);
        self::assertNotNull($result);
    }

    public function testFingerprintIgnoresConfirmFields(): void
    {
        $a = ArgsFingerprint::of(['sql' => 'SELECT 1', 'confirmation_id' => 'abc', 'confirmation_code' => '123456']);
        $b = ArgsFingerprint::of(['sql' => 'SELECT 1']);
        self::assertSame($a, $b);
    }

    public function testHasherRoundTrip(): void
    {
        $h = ConfirmCodeHasher::hash('123456');
        self::assertTrue(ConfirmCodeHasher::verify('123456', $h));
        self::assertFalse(ConfirmCodeHasher::verify('000000', $h));
    }

    public function testThreeBadAttemptsInvalidate(): void
    {
        $ctx = $this->adminCtx();
        $issued = $this->gate->check('iot_sql', ['sql' => 'DROP TABLE x'], $ctx, [ConfirmCategory::SQL_DDL], [
            'cible' => 't', 'effet' => 'e', 'risques' => [],
        ]);
        $id = (string) $issued->structured()['confirmation_id'];
        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->gate->check('iot_sql', [
                    'sql' => 'DROP TABLE x',
                    'confirmation_id' => $id,
                    'confirmation_code' => '111111',
                ], $ctx, [ConfirmCategory::SQL_DDL], ['cible' => 't', 'effet' => 'e', 'risques' => []]);
            } catch (ToolException) {
            }
        }
        $row = $this->repo->findConfirmation($id);
        self::assertNotNull($row['consumed_at']);
    }
}
