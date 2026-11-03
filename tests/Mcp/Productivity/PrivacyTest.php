<?php

declare(strict_types=1);

namespace Tests\Mcp\Productivity;

use App\Mcp\McpContext;
use App\Mcp\Support\Redactor;
use App\Mcp\Support\SecretScanner;
use App\Mcp\Tools\AccountsTool;
use App\Repository\McpReadRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

final class PrivacyTest extends TestCase
{
    public function testSecretScannerNeverKeepsApiKeysEvenWithKeepEmails(): void
    {
        $text = 'api_key=supersecretvalue1234567890123456 contact@example.com';
        $masked = SecretScanner::mask($text, true);
        self::assertStringNotContainsString('supersecretvalue', $masked);
        self::assertStringContainsString('contact@example.com', $masked);
    }

    public function testRedactorKeepEmails(): void
    {
        $text = 'Envoyé à admin@example.com token=abcdef0123456789abcdef0123456789';
        $with = Redactor::redact($text, 500, true);
        $without = Redactor::redact($text, 500, false);
        self::assertStringContainsString('admin@example.com', (string) $with);
        self::assertStringNotContainsString('admin@example.com', (string) $without);
        self::assertStringNotContainsString('abcdef0123456789abcdef0123456789', (string) $with);
    }

    public function testAccountsToolListsEmailsWithoutHashes(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Extension PDO SQLite absente.');
        }
        $pdo = McpSqliteFixture::pdo();
        $config = McpSqliteFixture::config(['MCP_PRIVACY_BYPASS' => 'true']);
        $notifier = $this->createConfiguredMock(NotificationService::class, [
            'recipient' => 'notif@example.test',
        ]);
        $tool = new AccountsTool($config, new UserRepository($pdo), $notifier, new McpReadRepository($pdo));
        $ctx = new McpContext(3, 'admin', 'admin', ['iot.read'], 'c', null, true, 'admin@example.test');
        $result = $tool->call([], $ctx);
        $data = $result->structured();
        self::assertNotEmpty($data['users']);
        $json = (string) json_encode($data);
        self::assertStringContainsString('admin@example.test', $json);
        foreach ($data['users'] as $user) {
            self::assertArrayNotHasKey('password_hash', $user);
        }
        self::assertStringNotContainsString('$2y$', $json);
        self::assertSame('notif@example.test', $data['recipients']['notif_email_recipient']);
    }

    public function testCanSeePersonalDataRequiresBoth(): void
    {
        $adminNo = new McpContext(3, 'admin', 'admin', ['iot.read'], 'c', null, false);
        $adminYes = new McpContext(3, 'admin', 'admin', ['iot.read'], 'c', null, true);
        $op = new McpContext(2, 'operateur', 'operator', ['iot.read'], 'c', null, true);
        self::assertFalse($adminNo->canSeePersonalData());
        self::assertTrue($adminYes->canSeePersonalData());
        self::assertFalse($op->canSeePersonalData());
    }
}
