<?php

namespace Tests\Service;

use App\Config\TableConfig;
use App\Service\PumpService;
use PDO;
use PHPUnit\Framework\TestCase;

class PumpServiceTest extends TestCase
{
    private PDO $pdo;
    private PumpService $service;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        TableConfig::setEnvironment('prod');

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

    /**
     * Contrat unique du GPIO 18 depuis la 6.30.0 : 1 = ON, 0 = OFF — comme la page de
     * contrôle, le GET /api/outputs/state et le firmware. L'ancienne logique actif-bas
     * était inverse du canal lu par l'ESP32 (un « arrêt » y commandait un démarrage).
     */
    public function testRunAndStopTankPumpUseOnIsOneConvention(): void
    {
        $this->service->runPompeTank();
        $this->assertSame(1, $this->service->getTankPumpState());

        $this->service->stopPompeTank();
        $this->assertSame(0, $this->service->getTankPumpState());
    }

    public function testRebootEspSetsResetMode(): void
    {
        $this->service->rebootEsp();
        $this->assertSame(1, $this->service->getResetModeState());
    }
}
