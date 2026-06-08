<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\AbstractSensorRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Couvre la logique commune d'AbstractSensorRepository (partagée par MSP1/N3PP)
 * via une sous-classe concrète minimale, sur SQLite en mémoire.
 */
final class AbstractSensorRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AbstractSensorRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE sensors_test (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sensor TEXT,
            version TEXT,
            TempAir REAL,
            reading_time TEXT
        )');

        $today = date('Y-m-d');
        $this->insert('s1', 'v1.0', 20.0, $today . ' 08:00:00');
        $this->insert('s1', 'v1.1', 21.5, $today . ' 12:00:00');
        $this->insert('s1', 'v1.2', 19.0, $today . ' 18:00:00');
        // Une mesure ancienne (hier) pour distinguer countReadingsToday / first date.
        $this->insert('s1', 'v0.9', 18.0, date('Y-m-d', strtotime('-1 day')) . ' 10:00:00');

        $this->repo = $this->makeRepo($this->pdo);
    }

    private function insert(string $sensor, string $version, float $temp, string $time): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sensors_test (sensor, version, TempAir, reading_time) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$sensor, $version, $temp, $time]);
    }

    private function makeRepo(PDO $pdo): AbstractSensorRepository
    {
        return new class ($pdo) extends AbstractSensorRepository {
            protected function getTableName(): string
            {
                return 'sensors_test';
            }

            /** @return string[] */
            public function getSensorColumns(): array
            {
                return ['TempAir'];
            }
        };
    }

    public function testGetLatestReturnsMostRecentRow(): void
    {
        $latest = $this->repo->getLatest();
        $this->assertNotNull($latest);
        $this->assertSame('v1.2', $latest['version']);
        $this->assertEqualsWithDelta(19.0, (float) $latest['TempAir'], 0.001);
    }

    public function testGetFirmwareVersionReturnsLatestVersion(): void
    {
        $this->assertSame('v1.2', $this->repo->getFirmwareVersion());
    }

    public function testFetchBetweenReturnsRowsInRangeOrderedAsc(): void
    {
        $today = date('Y-m-d');
        $rows = $this->repo->fetchBetween($today . ' 00:00:00', $today . ' 23:59:59');
        $this->assertCount(3, $rows);
        $this->assertSame('v1.0', $rows[0]['version']); // ASC
        $this->assertSame('v1.2', $rows[2]['version']);
    }

    public function testCountReadingsTodayExcludesPreviousDays(): void
    {
        $this->assertSame(3, $this->repo->countReadingsToday());
    }

    public function testLastAndFirstReadingDate(): void
    {
        $today = date('Y-m-d');
        $this->assertSame($today . ' 18:00:00', $this->repo->getLastReadingDate());
        $this->assertSame(
            date('Y-m-d', strtotime('-1 day')) . ' 10:00:00',
            $this->repo->getFirstReadingDate()
        );
    }

    public function testGetLatestReturnsNullOnEmptyTable(): void
    {
        $this->pdo->exec('DELETE FROM sensors_test');
        $this->assertNull($this->repo->getLatest());
        $this->assertSame('N/A', $this->repo->getFirmwareVersion());
        $this->assertNull($this->repo->getLastReadingDate());
    }
}
