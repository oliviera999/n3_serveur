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
        // Nettoie si fichier existe déjà
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        putenv('LOG_FILE_PATH=' . $this->logFile);
        $_ENV['LOG_FILE_PATH'] = $this->logFile;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        unset($_ENV['LOG_FILE_PATH']);
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
