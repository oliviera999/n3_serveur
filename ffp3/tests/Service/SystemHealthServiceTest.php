<?php

namespace Tests\Service;

use App\Service\SystemHealthService;
use App\Service\NotificationService;
use App\Service\LogService;
use App\Repository\SensorReadRepository;
use PHPUnit\Framework\TestCase;

class SystemHealthServiceTest extends TestCase
{
    public function testOfflineTriggersCriticalLog(): void
    {
        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('getLastReadingDate')->willReturn(date('Y-m-d H:i:s', strtotime('-3 hours')));

        $notifier = $this->createMock(NotificationService::class);

        $logger = $this->createMock(LogService::class);
        $logger->expects($this->once())->method('critical')->with($this->stringContains('hors ligne'));

        $service = new SystemHealthService($repo, $notifier, $logger);
        $service->checkOnlineStatus(3600); // 1h max offline
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
} 