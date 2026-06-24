<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\OutputService;
use PHPUnit\Framework\TestCase;

class OutputServiceManualFeedTest extends TestCase
{
    public function testIsManualFeedGpio(): void
    {
        $service = new OutputService(
            $this->createMock(OutputRepository::class),
            $this->createMock(BoardRepository::class),
            $this->createMock(SensorReadRepository::class),
        );

        $this->assertTrue($service->isManualFeedGpio(108));
        $this->assertTrue($service->isManualFeedGpio(109));
        $this->assertFalse($service->isManualFeedGpio(16));
    }

    public function testTriggerResetCallsUpdateByGpio(): void
    {
        $repo = $this->createMock(OutputRepository::class);
        $repo->expects($this->once())
            ->method('updateState')
            ->with(108, 0, 'web')
            ->willReturn(true);

        $service = new OutputService(
            $repo,
            $this->createMock(BoardRepository::class),
            $this->createMock(SensorReadRepository::class),
        );

        $result = $service->triggerManualFeedStep(3, 108, 'reset');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['state']);
    }

    public function testTriggerStepReturnsFeedCmdId(): void
    {
        $repo = $this->createMock(OutputRepository::class);
        $repo->expects($this->once())
            ->method('updateStateById')
            ->with(3, 1, 'web')
            ->willReturn(true);

        $service = new OutputService(
            $repo,
            $this->createMock(BoardRepository::class),
            $this->createMock(SensorReadRepository::class),
        );

        $result = $service->triggerManualFeedStep(3, 109, 'trigger');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['state']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $result['feed_cmd_id'] ?? '');
    }
}
