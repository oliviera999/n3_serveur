<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\ServerSettingsRepository;
use App\Service\HmacAuditLogger;
use PHPUnit\Framework\TestCase;

class HmacAuditLoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hmac-audit-test-' . uniqid('', true) . '.log';
        $_ENV['HMAC_AUDIT_LOG_PATH'] = $this->logFile;
        $_ENV['HMAC_AUDIT_LOG'] = 'true';
        $_ENV['HMAC_AUDIT_ROTATE_DAYS'] = '0';
        $_ENV['LOG_MASK_IP'] = 'false';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        unset($_ENV['HMAC_AUDIT_LOG_PATH'], $_ENV['HMAC_AUDIT_LOG']);
    }

    public function testRecordWritesWhenEnabled(): void
    {
        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->method('getBool')->willReturn(true);

        $logger = new HmacAuditLogger($settings);
        $logger->record('PostData', 'ok', 'x_sig_body', [
            'sensor' => 'esp32-wroom',
            'ip' => '10.0.0.1',
        ]);

        $this->assertFileExists($this->logFile);
        $content = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString(HmacAuditLogger::PREFIX, $content);
        $this->assertStringContainsString('result=ok', $content);
        $this->assertStringContainsString('component=PostData', $content);
        $this->assertStringContainsString('auth_mode=x_sig_body', $content);
    }

    public function testRecordSkippedWhenDisabled(): void
    {
        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->method('getBool')->willReturn(false);

        $logger = new HmacAuditLogger($settings);
        $logger->record('PostData', 'ok', 'x_sig_body');

        $this->assertFileDoesNotExist($this->logFile);
    }

    public function testReadTailReturnsRecentEntries(): void
    {
        $settings = $this->createMock(ServerSettingsRepository::class);
        $settings->method('getBool')->willReturn(true);

        $logger = new HmacAuditLogger($settings);
        $logger->record('MspPostData', 'reject', 'legacy_timestamp', [], 'signature_invalid');
        $logger->record('MspPostData', 'ok', 'legacy_timestamp');

        $entries = $logger->readTail(10);
        $this->assertCount(2, $entries);
        $this->assertStringContainsString('result=reject', $entries[0]['line']);
        $this->assertStringContainsString('result=ok', $entries[1]['line']);
    }
}
