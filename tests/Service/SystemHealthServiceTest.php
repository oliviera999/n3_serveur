<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Repository\SensorReadRepository;
use App\Service\LogService;
use App\Service\NotificationService;
use App\Service\SystemHealthService;
use PHPUnit\Framework\TestCase;

final class SystemHealthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');
        unset($_ENV['RESERVE_LOW_LEVEL_THRESHOLD']);
        putenv('RESERVE_LOW_LEVEL_THRESHOLD');
    }

    protected function tearDown(): void
    {
        unset($_ENV['RESERVE_LOW_LEVEL_THRESHOLD']);
        putenv('RESERVE_LOW_LEVEL_THRESHOLD');
    }

    public function testOfflineTriggersCriticalLog(): void
    {
        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('getLastReadingDate')->willReturn(date('Y-m-d H:i:s', strtotime('-3 hours')));

        $notifier = $this->createMock(NotificationService::class);
        $logger = $this->createMock(LogService::class);
        $logger->expects($this->once())->method('critical')->with($this->stringContains('hors ligne'));

        $service = new SystemHealthService($repo, $notifier, $logger);
        $service->checkOnlineStatus(3600);
    }

    public function testOnlineTriggersInfoLog(): void
    {
        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('getLastReadingDate')->willReturn(date('Y-m-d H:i:s', strtotime('-10 minutes')));

        $notifier = $this->createMock(NotificationService::class);
        $logger = $this->createMock(LogService::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('en ligne'));

        $service = new SystemHealthService($repo, $notifier, $logger);
        $service->checkOnlineStatus(3600);
    }

    public function testCheckTankLevelSkipsWhenThresholdUnset(): void
    {
        $repo = $this->createMock(SensorReadRepository::class);
        $repo->expects($this->never())->method('getLastReadings');

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $logger = $this->createMock(LogService::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('alerte désactivée'));

        $service = new SystemHealthService($repo, $notifier, $logger);
        $service->checkTankLevel();
    }

    public function testCheckTankLevelAlertsWhenReserveAboveThreshold(): void
    {
        $_ENV['RESERVE_LOW_LEVEL_THRESHOLD'] = '500';

        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('getLastReadings')->willReturn(['EauReserve' => 600.0]);

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->once())
            ->method('sendAlert')
            ->with(
                Severity::Alert,
                NotificationCategory::Hydraulic,
                'FFP3',
                'Niveau de réserve bas',
                $this->stringContains('600'),
                'ffp3:reserve-low'
            )
            ->willReturn(true);

        $logger = $this->createMock(LogService::class);

        $service = new SystemHealthService($repo, $notifier, $logger);
        $service->checkTankLevel();
    }

    /**
     * @dataProvider provideNoAlertReserveLevels
     */
    public function testCheckTankLevelNoAlertWhenAtOrBelowThresholdOrNull(?float $eauReserve): void
    {
        $_ENV['RESERVE_LOW_LEVEL_THRESHOLD'] = '500';

        $repo = $this->createMock(SensorReadRepository::class);
        if ($eauReserve === null) {
            $repo->method('getLastReadings')->willReturn([]);
        } else {
            $repo->method('getLastReadings')->willReturn(['EauReserve' => $eauReserve]);
        }

        $notifier = $this->createMock(NotificationService::class);
        $notifier->expects($this->never())->method('sendAlert');

        $logger = $this->createMock(LogService::class);

        $service = new SystemHealthService($repo, $notifier, $logger);
        $service->checkTankLevel();
    }

    /**
     * @return array<string, array{0: ?float}>
     */
    public static function provideNoAlertReserveLevels(): array
    {
        return [
            'below threshold' => [400.0],
            'at threshold' => [500.0],
            'null reading' => [null],
        ];
    }
}
