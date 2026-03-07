<?php

namespace Tests\Repository;

use App\Repository\SensorReadRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class SensorReadRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SensorReadRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ffp3Data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            EauAquarium REAL,
            reading_time TEXT
        )');

        // Insert two rows with different times
        $this->pdo->exec("INSERT INTO ffp3Data (EauAquarium, reading_time) VALUES (10.0, '2023-01-01 10:00:00')");
        $this->pdo->exec("INSERT INTO ffp3Data (EauAquarium, reading_time) VALUES (12.0, '2023-01-01 11:00:00')");

        $this->repo = new SensorReadRepository($this->pdo);
    }

    public function testGetLastReadingsSingle(): void
    {
        $last = $this->repo->getLastReadings();
        $this->assertSame(12.0, (float) $last['EauAquarium']);
    }

    public function testGetLastReadingsMultiple(): void
    {
        $two = $this->repo->getLastReadings(2);
        $this->assertCount(2, $two);
        $this->assertSame(12.0, (float) $two[0]['EauAquarium']);
        $this->assertSame(10.0, (float) $two[1]['EauAquarium']);
    }
} 