<?php

declare(strict_types=1);

namespace Tests\Command;

use App\Command\CronOrchestrator;
use App\Command\RestartPumpCommand;
use App\Repository\SensorReadRepository;
use App\Service\DeviceHealthService;
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

    /**
     * Aquarium bas = seuil de remplissage du firmware : le serveur alerte, mais ne touche
     * PAS à la pompe réserve (le pilotage et ses sécurités appartiennent à l'ESP32).
     */
    public function testLowWaterLevelAlertsWithoutTouchingTankPump(): void
    {
        $pump = $this->createMock(PumpService::class);
        $pump->expects($this->never())->method('stopPompeTank');
        $pump->expects($this->never())->method('runPompeTank');
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

    public function testNormalWaterLevelSendsNoAlert(): void
    {
        $pump = $this->createMock(PumpService::class);
        $pump->expects($this->never())->method('stopPompeTank');
        $pump->expects($this->never())->method('runPompeTank');
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

    public function testTankLevelCheckedEvenWhenHourlySkipped(): void
    {
        // Phase 1 arbitrage mails : la réserve basse est dans le bucket fréquent
        // (chaque minute), plus dans le bucket horaire.
        file_put_contents($this->tempDir . '/cron_last_hourly.timestamp', (string) time());

        $health = $this->createMock(SystemHealthService::class);
        $health->expects($this->never())->method('checkOnlineStatus');
        $health->expects($this->once())->method('checkTankLevel');

        $orchestrator = $this->buildOrchestrator(
            healthService: $health,
            statsService: $this->defaultStatsMock(),
            sensorReadRepo: $this->defaultRepoMock(),
        );

        $orchestrator->execute();
    }

    public function testTideCheckSkippedWhenPumpJustRestarted(): void
    {
        // Après consommation du flag (délai écoulé), l'écart-type est encore bas :
        // sans garde, checkTideSystem recouperait la pompe dans le même run.
        $restartPump = $this->createMock(RestartPumpCommand::class);
        $restartPump->expects($this->once())->method('execute')->willReturn(true);

        $pump = $this->createMock(PumpService::class);
        $pump->expects($this->never())->method('stopPompeAqua');
        $pump->method('getAquaPumpState')->willReturn(1);
        $pump->method('getTankPumpState')->willReturn(1);
        $pump->method('getResetModeState')->willReturn(0);

        $stats = $this->createMock(SensorStatisticsService::class);
        $stats->expects($this->never())->method('lastReadingsWindow');
        $stats->method('stddev')->willReturn(0.2);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('notifyMareesProblem');

        $orchestrator = $this->buildOrchestrator(
            pumpService: $pump,
            statsService: $stats,
            notifier: $notifier,
            sensorReadRepo: $this->defaultRepoMock(),
            restartPumpCommand: $restartPump,
        );

        $orchestrator->execute();

        $flagFile = $this->tempDir . '/pump_restart_scheduled.flag';
        $this->assertFileDoesNotExist($flagFile);
    }

    public function testTideCheckSkippedWhileRestartFlagPending(): void
    {
        // Phase 1 (CRON 1 min) : tant qu'un redémarrage pompe est programmé, la
        // détection de marée ne doit ni re-couper la pompe ni réécrire le flag
        // (sinon le délai horodaté repartirait de zéro à chaque tick).
        $flagFile = $this->tempDir . '/pump_restart_scheduled.flag';
        $scheduledAt = time() - 100;
        file_put_contents($flagFile, (string) $scheduledAt);

        $pump = $this->createMock(PumpService::class);
        $pump->expects($this->never())->method('stopPompeAqua');
        $pump->method('getAquaPumpState')->willReturn(0);
        $pump->method('getTankPumpState')->willReturn(1);
        $pump->method('getResetModeState')->willReturn(0);

        // Écart-type sous le seuil : déclencherait normalement l'arrêt + le flag.
        $stats = $this->createMock(SensorStatisticsService::class);
        $stats->method('lastReadingsWindow')->willReturn(self::flatLiveWindow());
        $stats->method('stddev')->willReturn(0.2);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('notifyMareesProblem');

        $orchestrator = $this->buildOrchestrator(
            pumpService: $pump,
            statsService: $stats,
            notifier: $notifier,
            sensorReadRepo: $this->defaultRepoMock(),
        );

        $orchestrator->execute();

        $this->assertSame((string) $scheduledAt, file_get_contents($flagFile), 'Le flag ne doit pas être réécrit');
        unlink($flagFile);
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
        ?DeviceHealthService $deviceHealthService = null,
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
        $deviceHealthService ??= $this->createMock(DeviceHealthService::class);
        if ($restartPumpCommand === null) {
            $restartPumpCommand = $this->createMock(RestartPumpCommand::class);
            $restartPumpCommand->method('execute')->willReturn(false);
        }

        return new CronOrchestrator(
            logger: $logger,
            sensorDataService: $sensorDataService,
            pumpService: $pumpService,
            statsService: $statsService,
            notifier: $notifier,
            sensorReadRepo: $sensorReadRepo,
            healthService: $healthService,
            deviceHealthService: $deviceHealthService,
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
        $stats->method('lastReadingsWindow')->willReturn(['rows' => 60, 'non_null' => 60, 'distinct' => 30, 'stddev' => 8.0]);
        $stats->method('stddev')->willReturn(1.5);

        return $stats;
    }

    /**
     * Marée réellement plate sur un signal vivant (pompe en panne) : seule situation qui coupe.
     *
     * @return array{rows: int, non_null: int, distinct: int, stddev: float|null}
     */
    private static function flatLiveWindow(): array
    {
        return ['rows' => 60, 'non_null' => 60, 'distinct' => 3, 'stddev' => 0.4];
    }

    /**
     * @param array{rows: int, non_null: int, distinct: int, stddev: float|null} $window
     */
    private function statsWith(array $window): SensorStatisticsService
    {
        $stats = $this->createMock(SensorStatisticsService::class);
        $stats->method('lastReadingsWindow')->willReturn($window);
        $stats->method('stddev')->willReturn(0.0);

        return $stats;
    }

    private function pumpMock(bool $expectCut): PumpService
    {
        $pump = $this->createMock(PumpService::class);
        $pump->expects($expectCut ? $this->once() : $this->never())->method('stopPompeAqua');
        $pump->method('getAquaPumpState')->willReturn(1);
        $pump->method('getTankPumpState')->willReturn(0);
        $pump->method('getResetModeState')->willReturn(0);

        return $pump;
    }

    public function testTideCutOnFlatLiveSignal(): void
    {
        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())->method('notifyMareesProblem');

        $this->buildOrchestrator(pumpService: $this->pumpMock(true), statsService: $this->statsWith(self::flatLiveWindow()), notifier: $notifier)->execute();

        $this->assertFileExists($this->tempDir . '/pump_restart_scheduled.flag');
        $state = json_decode((string) file_get_contents($this->tempDir . '/' . CronOrchestrator::TIDE_CUTS_STATE_FILENAME), true);
        $this->assertCount(1, $state['cuts']);
    }

    /**
     * @return iterable<string, array{array{rows: int, non_null: int, distinct: int, stddev: float|null}}>
     */
    public static function invalidWindows(): iterable
    {
        yield 'capteur figé (incident 30/10 : 60 × 81 mm)' => [['rows' => 60, 'non_null' => 60, 'distinct' => 1, 'stddev' => 0.0]];
        yield 'transition figé → muet (STDDEV 0 sur peu de valeurs)' => [['rows' => 60, 'non_null' => 10, 'distinct' => 1, 'stddev' => 0.0]];
        yield 'capteur muet (fenêtre NULL)' => [['rows' => 60, 'non_null' => 0, 'distinct' => 0, 'stddev' => null]];
    }

    /**
     * @dataProvider invalidWindows
     * @param array{rows: int, non_null: int, distinct: int, stddev: float|null} $window
     */
    public function testTideNeverCutsOnInvalidSensorData(array $window): void
    {
        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('notifyMareesProblem');
        $notifier->expects($this->never())->method('sendAlert');

        $this->buildOrchestrator(pumpService: $this->pumpMock(false), statsService: $this->statsWith($window), notifier: $notifier)->execute();

        $this->assertFileDoesNotExist($this->tempDir . '/pump_restart_scheduled.flag');
    }

    public function testTideBreakerRefusesCutsAndNotifiesOnce(): void
    {
        $recent = array_map(static fn (int $i): int => time() - 3600 * $i, range(1, 6));
        file_put_contents($this->tempDir . '/' . CronOrchestrator::TIDE_CUTS_STATE_FILENAME, (string) json_encode(['cuts' => $recent]));

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('notifyMareesProblem');
        $notifier->expects($this->once())->method('sendAlert')
            ->with($this->anything(), $this->anything(), 'FFP3', 'Coupures « marée » suspendues', $this->stringContains('6 fois en 24 h'), 'ffp3:tide-breaker')
            ->willReturn(true);

        $this->buildOrchestrator(pumpService: $this->pumpMock(false), statsService: $this->statsWith(self::flatLiveWindow()), notifier: $notifier)->execute();
        // Deuxième passage : disjoncteur toujours ouvert, déjà notifié → aucun nouvel e-mail.
        $this->buildOrchestrator(pumpService: $this->pumpMock(false), statsService: $this->statsWith(self::flatLiveWindow()), notifier: $notifier)->execute();
    }

    public function testTideCutsOlderThan24hDoNotCountTowardsBreaker(): void
    {
        $old = array_map(static fn (int $i): int => time() - 86400 - 60 * $i, range(1, 10));
        file_put_contents($this->tempDir . '/' . CronOrchestrator::TIDE_CUTS_STATE_FILENAME, (string) json_encode(['cuts' => $old]));

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())->method('notifyMareesProblem');
        $this->buildOrchestrator(pumpService: $this->pumpMock(true), statsService: $this->statsWith(self::flatLiveWindow()), notifier: $notifier)->execute();
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
