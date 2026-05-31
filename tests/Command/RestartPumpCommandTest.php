<?php

declare(strict_types=1);

namespace Tests\Command;

use App\Command\RestartPumpCommand;
use App\Service\LogService;
use App\Service\PumpService;
use PHPUnit\Framework\TestCase;

class RestartPumpCommandTest extends TestCase
{
    private string $tempDir;
    private string $flagFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/restart_pump_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        $this->flagFile = $this->tempDir . '/pump_restart_scheduled.flag';
        putenv('LOG_FILE_PATH=php://memory');
    }

    protected function tearDown(): void
    {
        if (is_file($this->flagFile)) {
            unlink($this->flagFile);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testNoOpWhenFlagMissing(): void
    {
        $pump = $this->createMock(PumpService::class);
        $pump->expects($this->never())->method('runPompeAqua');

        $command = new RestartPumpCommand(
            $pump,
            $this->createMock(LogService::class),
            $this->flagFile
        );
        $command->execute();
    }

    public function testWaitsWhenDelayNotElapsed(): void
    {
        $baseTime = 1_700_000_000;
        file_put_contents($this->flagFile, (string) ($baseTime - 100));

        $pump = $this->createMock(PumpService::class);
        $pump->expects($this->never())->method('runPompeAqua');

        $logger = $this->createMock(LogService::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Redémarrage programmé dans'));

        $command = new RestartPumpCommand(
            $pump,
            $logger,
            $this->flagFile,
            $baseTime
        );
        $command->execute();

        $this->assertFileExists($this->flagFile);
    }

    public function testRestartsPumpAndDeletesFlagAfter300Seconds(): void
    {
        $baseTime = 1_700_000_000;
        file_put_contents($this->flagFile, (string) ($baseTime - 301));

        $pump = $this->createMock(PumpService::class);
        $pump->expects($this->once())->method('runPompeAqua');

        $command = new RestartPumpCommand(
            $pump,
            $this->createMock(LogService::class),
            $this->flagFile,
            $baseTime
        );
        $command->execute();

        $this->assertFileDoesNotExist($this->flagFile);
    }
}
