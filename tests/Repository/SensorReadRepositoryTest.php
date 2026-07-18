<?php

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Repository\SensorReadRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class SensorReadRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SensorReadRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        TableConfig::setEnvironment('prod');

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

    /**
     * Construit un repository adossé à une table complète (toutes colonnes attendues
     * par fetchBetween/exportCsv/fetchTideSeriesBetween).
     */
    private function makeFullRepo(): SensorReadRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ffp3Data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            TempAir REAL, Humidite REAL, TempEau REAL,
            EauPotager REAL, EauAquarium REAL, EauReserve REAL, diffMaree REAL, Luminosite REAL,
            etatPompeAqua INTEGER, etatPompeTank INTEGER, etatHeat INTEGER, etatUV INTEGER,
            bouffePetits INTEGER, bouffeGros INTEGER, reading_time TEXT
        )');
        $insert = 'INSERT INTO ffp3Data
            (TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve, diffMaree, Luminosite,
             etatPompeAqua, etatPompeTank, etatHeat, etatUV, bouffePetits, bouffeGros, reading_time)
            VALUES (20,50,22,100,?,?,?,500,0,0,0,0,0,0,?)';
        $stmt = $pdo->prepare($insert);
        // 3 lignes chronologiques (EauAquarium, EauReserve, diffMaree, reading_time)
        $stmt->execute([300, 500, 11, '2026-05-25 10:00:00']);
        $stmt->execute([320, 490, 12, '2026-05-25 10:10:00']);
        $stmt->execute([310, 480, 13, '2026-05-25 10:20:00']);

        return new SensorReadRepository($pdo);
    }

    public function testFetchBetweenDescByDefault(): void
    {
        $repo = $this->makeFullRepo();
        $rows = $repo->fetchBetween('2026-05-25 09:00:00', '2026-05-25 11:00:00');

        $this->assertCount(3, $rows);
        // DESC : la plus récente en premier.
        $this->assertSame('2026-05-25 10:20:00', $rows[0]['reading_time']);
        $this->assertSame('2026-05-25 10:00:00', $rows[2]['reading_time']);
    }

    public function testFetchBetweenAscReturnsChronologicalOrder(): void
    {
        $repo = $this->makeFullRepo();
        $rows = $repo->fetchBetween('2026-05-25 09:00:00', '2026-05-25 11:00:00', 'ASC');

        $this->assertCount(3, $rows);
        // ASC : la plus ancienne en premier.
        $this->assertSame('2026-05-25 10:00:00', $rows[0]['reading_time']);
        $this->assertSame('2026-05-25 10:20:00', $rows[2]['reading_time']);
    }

    public function testFetchBetweenInvalidOrderFallsBackToDesc(): void
    {
        $repo = $this->makeFullRepo();
        // Une valeur arbitraire (potentielle injection) doit retomber sur DESC.
        $rows = $repo->fetchBetween('2026-05-25 09:00:00', '2026-05-25 11:00:00', 'ASC; DROP TABLE ffp3Data');

        $this->assertCount(3, $rows);
        $this->assertSame('2026-05-25 10:20:00', $rows[0]['reading_time']);
    }

    public function testFetchTideSeriesBetweenReturnsFourColumnsAsc(): void
    {
        $repo = $this->makeFullRepo();
        $rows = $repo->fetchTideSeriesBetween('2026-05-25 09:00:00', '2026-05-25 11:00:00');

        $this->assertCount(3, $rows);
        $this->assertSame('2026-05-25 10:00:00', $rows[0]['reading_time']);
        $this->assertSame(
            ['EauAquarium', 'EauReserve', 'diffMaree', 'reading_time'],
            array_keys($rows[0])
        );
    }

    public function testExportCsvStreamsRowsToFile(): void
    {
        $repo = $this->makeFullRepo();
        $tmp = tempnam(sys_get_temp_dir(), 'csvtest_');
        $this->assertNotFalse($tmp);

        $count = $repo->exportCsv('2026-05-25 09:00:00', '2026-05-25 11:00:00', $tmp);

        $this->assertSame(3, $count);
        $lines = file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // 1 ligne d'en-tête + 3 lignes de données.
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('reading_time', $lines[0]);
        @unlink($tmp);
    }

    public function testExportCsvWritesHeaderOnlyWhenNoRows(): void
    {
        // Bug B1 : sur une plage vide, exportCsv retourne toujours 0 MAIS écrit désormais un
        // fichier contenant la seule ligne d'en-tête (colonnes), afin que CsvExportService
        // puisse renvoyer un CSV vide valide au lieu de planter (HTTP 500).
        $repo = $this->makeFullRepo();
        $tmp = sys_get_temp_dir() . '/csvtest_empty_' . uniqid() . '.csv';

        $count = $repo->exportCsv('2030-01-01 00:00:00', '2030-01-02 00:00:00', $tmp);

        $this->assertSame(0, $count);
        $this->assertFileExists($tmp);
        $lines = file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // Une seule ligne : l'en-tête, aucune ligne de données.
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('reading_time', $lines[0]);
        @unlink($tmp);
    }
}
