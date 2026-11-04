<?php

declare(strict_types=1);

namespace Tests\Mcp\Control;

use App\Mcp\Control\OutputWriter;
use App\Repository\MspOutputRepository;
use App\Repository\N3ppOutputRepository;
use App\Repository\OutputRepository;
use App\Service\OutputService;
use PHPUnit\Framework\TestCase;

final class OutputWriterPumpRestoreTest extends TestCase
{
    public function testAquariumPumpWithoutRowIdIsWrittenByGpio(): void
    {
        $service = $this->createMock(OutputService::class);
        $service->expects($this->once())->method('updateStateByGpio')->with(16, 1)->willReturn(true);
        $service->expects($this->never())->method('updateStateById');

        $repo = $this->createMock(OutputRepository::class);
        $repo->expects($this->once())->method('findByGpio')->with(16)->willReturn(['id' => 4, 'state' => 1]);

        $writer = $this->writer($service, $repo);

        self::assertSame('1', $writer->write('ffp3', 16, 1, 0));
    }

    public function testOtherSwitchWithoutRowIdIsStillRefused(): void
    {
        $service = $this->createMock(OutputService::class);
        $service->expects($this->never())->method('updateStateByGpio');
        $service->expects($this->never())->method('updateStateById');

        self::assertNull($this->writer($service, $this->createMock(OutputRepository::class))->write('ffp3', 15, 1, 0));
    }

    public function testFailedForceModeDoesNotReportTheOldState(): void
    {
        $service = $this->createMock(OutputService::class);
        $service->expects($this->once())->method('updatePumpForceMode')->with(0)->willReturn(false);

        $repo = $this->createMock(OutputRepository::class);
        $repo->expects($this->never())->method('findByGpio');

        self::assertNull($this->writer($service, $repo)->write('ffp3', 117, 0, 0));
    }

    private function writer(OutputService $service, OutputRepository $repo): OutputWriter
    {
        return new OutputWriter(
            $service,
            $repo,
            $this->createMock(N3ppOutputRepository::class),
            $this->createMock(MspOutputRepository::class),
        );
    }
}
