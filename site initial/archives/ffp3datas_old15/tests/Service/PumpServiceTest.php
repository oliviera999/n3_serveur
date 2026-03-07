<?php

namespace Tests\Service;

use App\Service\PumpService;
use PDO;
use PHPUnit\Framework\TestCase;

class PumpServiceTest extends TestCase
{
    private PDO $pdo;
    private PumpService $service;

    protected function setUp(): void
    {
        // Configure GPIO via env
        putenv('GPIO_POMPE_AQUA=1');
        putenv('GPIO_POMPE_TANK=2');
        putenv('GPIO_RESET_MODE=3');
        putenv('LOG_FILE_PATH=php://memory');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ffp3Outputs (gpio INTEGER PRIMARY KEY, state INTEGER)');
        $this->pdo->exec('INSERT INTO ffp3Outputs (gpio, state) VALUES (1, 0), (2, 1), (3, 0)');

        $this->service = new PumpService($this->pdo);
    }

    public function testRunAndStopAquaPump(): void
    {
        // Start pump
        $this->service->runPompeAqua();
        $this->assertSame(1, $this->service->getAquaPumpState());

        // Stop pump
        $this->service->stopPompeAqua();
        $this->assertSame(0, $this->service->getAquaPumpState());
    }

    public function testRunAndStopTankPumpInvertedLogic(): void
    {
        // Tank pump logic inverted (1 = off)
        $this->service->runPompeTank(); // sets state 0
        $this->assertSame(0, $this->service->getTankPumpState());

        $this->service->stopPompeTank(); // sets state 1
        $this->assertSame(1, $this->service->getTankPumpState());
    }

    public function testRebootEspSetsResetMode(): void
    {
        $this->service->rebootEsp();
        $this->assertSame(1, $this->service->getResetModeState());
    }
} 