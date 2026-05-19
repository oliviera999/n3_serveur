<?php

namespace Tests\Service;

use App\Service\LogService;
use PHPUnit\Framework\TestCase;

class LogServiceTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logservice_test.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        putenv('LOG_FILE_PATH=' . $this->logFile);
        $_ENV['LOG_FILE_PATH'] = $this->logFile;
        // Desactive RotatingFileHandler pour ce test (sinon le fichier reel est
        // suffixe par la date : logservice_test-YYYY-MM-DD.log, et le test ne le trouve pas).
        $_ENV['LOG_ROTATE_DAYS'] = '0';
        // Desactive masquage IP (pas pertinent pour ces tests de format brut)
        $_ENV['LOG_MASK_IP'] = 'false';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        unset($_ENV['LOG_FILE_PATH'], $_ENV['LOG_ROTATE_DAYS'], $_ENV['LOG_MASK_IP']);
    }

    public function testInfoWritesFormattedLine(): void
    {
        $logger = new LogService();
        $logger->info('Test message');

        $contents = file_get_contents($this->logFile);
        $this->assertNotFalse($contents);
        $this->assertStringContainsString('INFO', $contents);
        $this->assertStringContainsString('Test message', $contents);
        // Vérifie format général : [YYYY-MM-DD HH:MM:SS] [LEVEL] Message
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[INFO\] Test message$/m', trim($contents));
    }

    public function testAddNameWritesWithoutNewline(): void
    {
        $logger = new LogService();
        $logger->addName('NAME');
        $logger->addTask('TASK');

        $contents = file_get_contents($this->logFile);
        $this->assertNotFalse($contents);
        $this->assertStringContainsString('[DEBUG] NAME', $contents);
        $this->assertStringContainsString('[INFO] TASK', $contents);
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[DEBUG\] NAME\\R\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[INFO\] TASK$/m',
            trim($contents)
        );
    }
}
