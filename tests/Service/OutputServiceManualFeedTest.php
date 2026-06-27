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

    public function testTriggerManualFeedIncrementsCounterAndReturnsCmdId(): void
    {
        $repo = $this->createMock(OutputRepository::class);
        $repo->expects($this->once())
            ->method('incrementFeedCounter')
            ->with(3, 109)
            ->willReturn(42);

        $service = new OutputService(
            $repo,
            $this->createMock(BoardRepository::class),
            $this->createMock(SensorReadRepository::class),
        );

        $result = $service->triggerManualFeed(3, 109);

        $this->assertTrue($result['success']);
        $this->assertSame(109, $result['gpio']);
        $this->assertSame(42, $result['counter']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $result['feed_cmd_id']);
    }

    public function testTriggerManualFeedRejectsNonFeedGpio(): void
    {
        $repo = $this->createMock(OutputRepository::class);
        $repo->expects($this->never())->method('incrementFeedCounter');

        $service = new OutputService(
            $repo,
            $this->createMock(BoardRepository::class),
            $this->createMock(SensorReadRepository::class),
        );

        $result = $service->triggerManualFeed(3, 16);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testTriggerManualFeedFailsWhenRowMissing(): void
    {
        $repo = $this->createMock(OutputRepository::class);
        $repo->expects($this->once())
            ->method('incrementFeedCounter')
            ->with(999, 108)
            ->willReturn(null);

        $service = new OutputService(
            $repo,
            $this->createMock(BoardRepository::class),
            $this->createMock(SensorReadRepository::class),
        );

        $result = $service->triggerManualFeed(999, 108);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['counter']);
    }
}
