<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Repository\EnergieSensorRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\EnergieSqliteSchema;

/**
 * AbstractSensorRepository::getLastNonNull() (6.50.0) : dernière valeur valide d'une
 * colonne pour les cartes dont la mesure courante est vide.
 */
final class LastNonNullReadingTest extends TestCase
{
    private PDO $pdo;
    private EnergieSensorRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        EnergieSqliteSchema::create($this->pdo);
        TableConfig::setEnvironment('energie_test');
        $this->repo = new EnergieSensorRepository($this->pdo);

        $insert = $this->pdo->prepare('INSERT INTO energieDataTest (sensor, version, Rssi, BatterieV, reading_time) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['energie', '0.3.1', -61, 12.8, '2026-10-30 10:00:00']);
        $insert->execute(['energie', '0.3.1', -63, 12.7, '2026-10-31 21:07:41']);
        $insert->execute(['energie', '0.3.1', null, 12.6, '2026-11-02 14:20:35']);
    }

    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
        parent::tearDown();
    }

    public function testReturnsLatestNonNullValueWithItsTimestamp(): void
    {
        $this->assertSame(
            ['value' => -63.0, 'reading_time' => '2026-10-31 21:07:41'],
            $this->repo->getLastNonNull('Rssi', '2026-10-01 00:00:00')
        );
    }

    public function testSearchIsBoundedAndColumnsAreWhitelisted(): void
    {
        $this->assertNull($this->repo->getLastNonNull('Rssi', '2026-11-01 00:00:00'), 'aucune valeur après la borne');
        $this->assertNull($this->repo->getLastNonNull('reading_time', '2026-10-01 00:00:00'), 'colonne hors liste capteurs');
        $this->assertNull($this->repo->getLastNonNull('Rssi`; DROP TABLE energieDataTest; --', '2026-10-01 00:00:00'));
    }
}
