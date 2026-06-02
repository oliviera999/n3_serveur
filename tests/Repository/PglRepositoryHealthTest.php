<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\PglConfig;
use App\Repository\PglRepository;
use PHPUnit\Framework\TestCase;

final class PglRepositoryHealthTest extends TestCase
{
    public function testGetSystemHealthOfflineWhenNoData(): void
    {
        $repo = new class extends PglRepository {
            public function __construct()
            {
            }

            public function getLastHeartbeatDate(): ?string
            {
                return null;
            }

            public function getLastEventDate(string $boardId = PglConfig::DEFAULT_BOARD_ID): ?string
            {
                return null;
            }
        };

        $health = $repo->getSystemHealth(300);
        $this->assertFalse($health['online']);
        $this->assertNull($health['last_reading']);
    }

    public function testGetSystemHealthOnlineFromRecentHeartbeat(): void
    {
        $recent = date('Y-m-d H:i:s', time() - 60);

        $repo = new class($recent) extends PglRepository {
            public function __construct(private string $hb)
            {
            }

            public function getLastHeartbeatDate(): ?string
            {
                return $this->hb;
            }

            public function getLastEventDate(string $boardId = PglConfig::DEFAULT_BOARD_ID): ?string
            {
                return null;
            }
        };

        $health = $repo->getSystemHealth(300);
        $this->assertTrue($health['online']);
        $this->assertSame('heartbeat', $health['source']);
        $this->assertSame($recent, $health['last_reading']);
    }

    public function testGetSystemHealthUsesMostRecentSource(): void
    {
        $oldHb = date('Y-m-d H:i:s', time() - 3600);
        $recentEv = date('Y-m-d H:i:s', time() - 30);

        $repo = new class($oldHb, $recentEv) extends PglRepository {
            public function __construct(private string $hb, private string $ev)
            {
            }

            public function getLastHeartbeatDate(): ?string
            {
                return $this->hb;
            }

            public function getLastEventDate(string $boardId = PglConfig::DEFAULT_BOARD_ID): ?string
            {
                return $this->ev;
            }
        };

        $health = $repo->getSystemHealth(300);
        $this->assertTrue($health['online']);
        $this->assertSame('event', $health['source']);
        $this->assertSame($recentEv, $health['last_reading']);
    }
}
