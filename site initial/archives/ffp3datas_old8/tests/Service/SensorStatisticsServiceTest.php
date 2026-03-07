<?php

namespace Tests\Service;

use App\Service\SensorStatisticsService;
use PDO;
use PHPUnit\Framework\TestCase;

class SensorStatisticsServiceTest extends TestCase
{
    private PDO $pdo;
    private SensorStatisticsService $service;

    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ffp3Data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            TempAir REAL,
            Humidite REAL,
            TempEau REAL,
            EauPotager REAL,
            EauAquarium REAL,
            EauReserve REAL,
            Luminosite REAL,
            reading_time TEXT
        )');

        // Insère 3 lectures simples
        $times = ['2023-01-01 10:00:00', '2023-01-01 11:00:00', '2023-01-01 12:00:00'];
        $values = [10.0, 15.0, 20.0];
        foreach ($values as $i => $val) {
            $stmt = $this->pdo->prepare('INSERT INTO ffp3Data (TempAir, reading_time) VALUES (:temp, :time)');
            $stmt->execute([':temp' => $val, ':time' => $times[$i]]);
        }

        $this->service = new SensorStatisticsService($this->pdo);
    }

    public function testMinMaxAvg(): void
    {
        $start = '2023-01-01 09:00:00';
        $end   = '2023-01-01 13:00:00';

        $this->assertSame(10.0, $this->service->min('TempAir', $start, $end));
        $this->assertSame(20.0, $this->service->max('TempAir', $start, $end));
        $this->assertSame(15.0, $this->service->avg('TempAir', $start, $end));
    }
} 