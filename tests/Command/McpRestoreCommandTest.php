<?php

declare(strict_types=1);

namespace Tests\Command;

use App\Command\McpRestoreCommand;
use App\Mcp\Control\OutputWriter;
use App\Repository\McpRestoreRepository;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;

final class McpRestoreCommandTest extends TestCase
{
    public function testRestoreWritesForceAutoThenPumpOnAndMarksDone(): void
    {
        $repo = $this->createMock(McpRestoreRepository::class);
        $repo->method('duePending')->willReturn([
            ['id' => 3, 'family' => 'ffp3', 'env' => 'test'],
        ]);
        $repo->expects($this->once())->method('markDone')->with(3);
        $repo->expects($this->never())->method('markFailed');
        $repo->method('purgeOld')->willReturn(0);

        $seen = [];
        $writer = $this->createMock(OutputWriter::class);
        $writer->expects($this->exactly(2))->method('write')->willReturnCallback(
            function (string $family, int $gpio, int|float $value, int $id) use (&$seen): string {
                $seen[] = [$family, $gpio, (int) $value, $id];

                return $gpio === 117 ? '0' : '1';
            }
        );

        $cmd = $this->command($repo, $writer, $this->createMock(NotificationService::class));
        $result = $cmd->execute();

        self::assertSame([['ffp3', 117, 0, 0], ['ffp3', 16, 1, 0]], $seen);
        self::assertSame(1, $result['done']);
        self::assertSame(0, $result['failed']);
    }

    public function testPumpStillOffIsNotMarkedDone(): void
    {
        $repo = $this->createMock(McpRestoreRepository::class);
        $repo->method('duePending')->willReturn([
            ['id' => 7, 'family' => 'ffp3', 'env' => 'test'],
        ]);
        $repo->expects($this->never())->method('markDone');
        $repo->expects($this->once())->method('markFailed')->with(7, $this->stringContains('GPIO 16'));
        $repo->method('purgeOld')->willReturn(0);

        $writer = $this->createMock(OutputWriter::class);
        $writer->method('write')->willReturnCallback(
            static fn (string $family, int $gpio): ?string => $gpio === 117 ? '0' : null
        );

        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('sendImmediateAlert');

        $result = $this->command($repo, $writer, $notifications)->execute();

        self::assertSame(0, $result['done']);
        self::assertSame(1, $result['failed']);
    }

    private function command(
        McpRestoreRepository $repo,
        OutputWriter $writer,
        NotificationService $notifications,
    ): McpRestoreCommand {
        return new McpRestoreCommand(
            $repo,
            $writer,
            $this->createMock(ControlAuditLogger::class),
            $notifications,
            $this->createMock(LogService::class),
        );
    }
}
