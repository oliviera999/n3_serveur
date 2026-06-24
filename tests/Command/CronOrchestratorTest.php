<?php

declare(strict_types=1);

namespace Tests\Command;

use App\Command\CronOrchestrator;
use App\Command\RestartPumpCommand;
use App\Repository\SensorReadRepository;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Service\PumpService;
use App\Service\SensorDataService;
use App\Service\SensorStatisticsService;
use App\Service\SystemHealthService;
use PHPUnit\Framework\TestCase;

class CronOrchestratorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cron_orch_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        putenv('LOG_FILE_PATH=php://memory');
        $_ENV['AQUA_LOW_LEVEL_THRESHOLD'] = '180';
        $_ENV['TIDE_STDDEV_THRESHOLD'] = '1';
        $_ENV['CRON_HOURLY_INTERVAL_SECONDS'] = '3600';
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testExitsEarlyWhenLockHeld(): void
    {
        $lockPath = $this->tempDir . '/cron_orchestrator.lock';
        $lockHandle = fopen($lockPath, 'c');
        $this->assertNotFalse($lockHandle);
        flock($lockHandle, LOCK_EX);

        $logger = $this->createMock(LogService::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('CronOrchestrator déjà en cours'));

        $orchestrator = $this->buildOrchestrator(logger: $logger);
        $orchestrator->execute();

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    public function testRunsFrequentTasksEveryExecution(): void
    {
        $restartPump = $this->createMock(RestartPumpCommand::class);
        $restartPump->expects($this->once())->method('execute');

        $sensorData = $this->createMock(SensorDataService::class);
        $sensorData->expects($this->once())->method('cleanAllSensorData')->willReturn([]);

        $stats = $this->createMock(SensorStatisticsService::class);
        $stats->method('stddevOnLastReadings')->willReturn(2.0);
        $stats->method('stddev')->willReturn(1.5);

        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('getLastReadings')->willReturn(['EauAquarium' => 150.0]);

        $health = $this->createMock(SystemHealthService::class);
        $health->expects($this->never())->method('checkOnlineStatus');

        file_put_contents($this->tempDir . '/cron_last_hourly.timestamp', (string) time());

        $orchestrator = $this->buildOrchestrator(
            logger: $this->createMock(LogService::class),
            sensorDataService: $sensorData,
            statsService: $stats,
            sensorReadRepo: $repo,
            healthService: $health,
            restartPumpCommand: $restartPump,
        );

        $orchestrator->execute();
    }

    public function testHourlyTasksSkippedWhenNotDue(): void
    {
        file_put_contents($this->tempDir . '/cron_last_hourly.timestamp', (string) time());

        $health = $this->createMock(SystemHealthService::class);
        $health->expects($this->never())->method('checkOnlineStatus');

        $orchestrator = $this->buildOrchestrator(
            healthService: $health,
            statsService: $this->defaultStatsMock(),
            sensorReadRepo: $this->defaultRepoMock(),
        );

        $orchestrator->execute();
    }

    public function testHourlyTasksRunWhenIntervalElapsed(): void
    {
        file_put_contents($this->tempDir . '/cron_last_hourly.timestamp', (string) (time() - 7200));

        $health = $this->createMock(SystemHealthService::class);
        $health->expects($this->once())->method('checkOnlineStatus');
        $health->expects($this->once())->method('checkTankLevel');

        $orchestrator = $this->buildOrchestrator(
            healthService: $health,
            statsService: $this->defaultStatsMock(),
            sensorReadRepo: $this->defaultRepoMock(),
        );

        $orchestrator->execute();

        $this->assertFileExists($this->tempDir . '/cron_last_hourly.timestamp');
        $this->assertGreaterThan(time() - 10, (int) file_get_contents($this->tempDir . '/cron_last_hourly.timestamp'));
    }

    public function testLowWaterLevelStopsTankPump(): void
    {
        $pump = $this->createMock(PumpService::class);
        $pump->expects($this->once())->method('stopPompeTank');
        $pump->method('getAquaPumpState')->willReturn(0);
        $pump->method('getTankPumpState')->willReturn(1);
        $pump->method('getResetModeState')->willReturn(0);

        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('getLastReadings')->willReturn(['EauAquarium' => 210.0]);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())->method('sendAlert');

        $orchestrator = $this->buildOrchestrator(
            pumpService: $pump,
            sensorReadRepo: $repo,
            notifier: $notifier,
            statsService: $this->defaultStatsMock(),
        );

        $orchestrator->execute();
    }

    public function testNormalWaterLevelDoesNotStopTankPump(): void
    {
        $pump = $this->createMock(PumpService::class);
        $pump->expects($this->never())->method('stopPompeTank');
        $pump->method('getAquaPumpState')->willReturn(0);
        $pump->method('getTankPumpState')->willReturn(1);
        $pump->method('getResetModeState')->willReturn(0);

        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('getLastReadings')->willReturn(['EauAquarium' => 150.0]);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $orchestrator = $this->buildOrchestrator(
            pumpService: $pump,
            sensorReadRepo: $repo,
            notifier: $notifier,
            statsService: $this->defaultStatsMock(),
        );

        $orchestrator->execute();
    }

    public function testCleaningCalledOncePerRun(): void
    {
        $sensorData = $this->createMock(SensorDataService::class);
        $sensorData->expects($this->once())->method('cleanAllSensorData')->willReturn([]);

        $orchestrator = $this->buildOrchestrator(
            sensorDataService: $sensorData,
            statsService: $this->defaultStatsMock(),
            sensorReadRepo: $this->defaultRepoMock(),
        );

        $orchestrator->execute();
    }

    private function buildOrchestrator(
        ?LogService $logger = null,
        ?SensorDataService $sensorDataService = null,
        ?PumpService $pumpService = null,
        ?SensorStatisticsService $statsService = null,
        ?NotificationService $notifier = null,
        ?SensorReadRepository $sensorReadRepo = null,
        ?SystemHealthService $healthService = null,
        ?RestartPumpCommand $restartPumpCommand = null,
    ): CronOrchestrator {
        $logger ??= $this->createMock(LogService::class);
        $sensorDataService ??= $this->createMock(SensorDataService::class);
        $sensorDataService->method('cleanAllSensorData')->willReturn([]);
        $pumpService ??= $this->createMock(PumpService::class);
        $pumpService->method('getAquaPumpState')->willReturn(0);
        $pumpService->method('getTankPumpState')->willReturn(1);
        $pumpService->method('getResetModeState')->willReturn(0);
        $statsService ??= $this->defaultStatsMock();
        $notifier ??= $this->createMock(NotificationService::class);
        $sensorReadRepo ??= $this->defaultRepoMock();
        $healthService ??= $this->createMock(SystemHealthService::class);
        $restartPumpCommand ??= $this->createMock(RestartPumpCommand::class);

        return new CronOrchestrator(
            logger: $logger,
            sensorDataService: $sensorDataService,
            pumpService: $pumpService,
            statsService: $statsService,
            notifier: $notifier,
            sensorReadRepo: $sensorReadRepo,
            healthService: $healthService,
            restartPumpCommand: $restartPumpCommand,
            lockDir: $this->tempDir,
            stateDir: $this->tempDir,
            pumpRestartFlagFile: $this->tempDir . '/pump_restart_scheduled.flag',
        );
    }

    private function defaultStatsMock(): SensorStatisticsService
    {
        $stats = $this->createMock(SensorStatisticsService::class);
        $stats->method('stddevOnLastReadings')->willReturn(2.0);
        $stats->method('stddev')->willReturn(1.5);

        return $stats;
    }

    private function defaultRepoMock(): SensorReadRepository
    {
        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('getLastReadings')->willReturn(['EauAquarium' => 150.0]);

        return $repo;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }
}
