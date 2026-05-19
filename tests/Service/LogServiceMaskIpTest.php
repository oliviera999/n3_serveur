<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\LogService;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie le masquage des adresses IP dans les logs (LOG_MASK_IP=true par défaut).
 * Le test écrit dans un fichier temporaire et vérifie que l'IP n'apparaît pas brut.
 */
final class LogServiceMaskIpTest extends TestCase
{
    private string $logFile;
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/log-mask-ip-' . uniqid() . '.log';
        foreach (['LOG_FILE_PATH', 'LOG_LEVEL', 'LOG_ROTATE_DAYS', 'LOG_MASK_IP'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
        }
        $_ENV['LOG_FILE_PATH'] = $this->logFile;
        $_ENV['LOG_LEVEL'] = 'DEBUG';
        $_ENV['LOG_ROTATE_DAYS'] = '0';
        $_ENV['LOG_MASK_IP'] = 'true';
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    public function testIpv4LastOctetMasked(): void
    {
        $service = new LogService();
        $service->warning('Test {ip}', ['ip' => '192.168.1.42']);
        $service->error('Erreur {client_ip}', ['client_ip' => '10.0.5.7']);

        $content = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('192.168.1.0', $content);
        $this->assertStringContainsString('10.0.5.0', $content);
        $this->assertStringNotContainsString('192.168.1.42', $content);
        $this->assertStringNotContainsString('10.0.5.7', $content);
    }

    public function testIpMaskingDisabledByEnv(): void
    {
        $_ENV['LOG_MASK_IP'] = 'false';
        $service = new LogService();
        $service->warning('Test {ip}', ['ip' => '203.0.113.55']);

        $content = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('203.0.113.55', $content);
    }

    public function testInvalidIpReturnsPlaceholder(): void
    {
        $service = new LogService();
        $service->warning('Test {ip}', ['ip' => 'not-an-ip']);
        $content = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('invalid', $content);
    }
}
