<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Repository\AbstractSensorRepository;
use App\Repository\MspSensorRepository;
use App\Repository\N3ppSensorRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Non-régression de l'export CSV de /meteo (MSP1) et /serre (N3PP) : avant 6.40.1,
 * ces repositories n'avaient pas d'exportCsv() → Error « undefined method » → HTTP 500
 * sur le bouton « Exporter CSV ». L'export vient désormais d'AbstractSensorRepository.
 * SQLite en mémoire, tables de test résolues via TableConfig (msp_test / n3pp_test).
 */
final class SensorCsvExportTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
        parent::tearDown();
    }

    /** @param string[] $columns */
    private function createTable(string $table, array $columns): void
    {
        $defs = array_map(static fn (string $c): string => "{$c} REAL", $columns);
        $this->pdo->exec("CREATE TABLE {$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sensor TEXT,
            version TEXT,
            " . implode(",\n            ", $defs) . ',
            reading_time TEXT
        )');
    }

    /** @param array<string, float|int> $values */
    private function insertRow(string $table, string $sensor, string $time, array $values = []): void
    {
        $columns = ['sensor', 'version', 'reading_time', ...array_keys($values)];
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})"
        );
        $stmt->execute([$sensor, '1.0', $time, ...array_values($values)]);
    }

    /**
     * @return array{0: int, 1: list<list<string|null>>}
     */
    private function export(AbstractSensorRepository $repo): array
    {
        $file = tempnam(sys_get_temp_dir(), 'sensor_csv_');
        $this->assertIsString($file);

        try {
            $count = $repo->exportCsv('2026-01-01 00:00:00', '2026-01-01 23:59:59', $file);
            $lines = file($file, FILE_IGNORE_NEW_LINES);
        } finally {
            @unlink($file);
        }

        $this->assertIsArray($lines);
        $rows = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', '\\'), $lines);

        return [$count, $rows];
    }

    public function testMspExportWritesHeaderAndRowsOfTestTable(): void
    {
        TableConfig::setEnvironment('msp_test');
        $repo = new MspSensorRepository($this->pdo);
        $this->createTable('msp1DataTest', $repo->getSensorColumns());
        $this->insertRow('msp1DataTest', 'msp1', '2026-01-01 10:00:00', ['TempAirExt' => 12.5]);
        $this->insertRow('msp1DataTest', 'msp1', '2026-01-01 09:00:00', ['TempAirExt' => 11.0]);
        $this->insertRow('msp1DataTest', 'msp1', '2026-01-02 09:00:00', ['TempAirExt' => 99.0]);

        [$count, $rows] = $this->export($repo);

        $this->assertSame(2, $count);
        $this->assertCount(3, $rows);
        $this->assertSame(
            ['id', 'sensor', 'version', ...$repo->getSensorColumns(), 'reading_time'],
            $rows[0]
        );
        $tempCol = array_search('TempAirExt', $rows[0], true);
        $this->assertIsInt($tempCol);
        // Tri chronologique croissant, mesure hors plage exclue.
        $this->assertSame('2026-01-01 09:00:00', $rows[1][count($rows[0]) - 1]);
        $this->assertEqualsWithDelta(11.0, (float) $rows[1][$tempCol], 0.001);
        $this->assertEqualsWithDelta(12.5, (float) $rows[2][$tempCol], 0.001);
    }

    public function testN3ppExportAppliesSameQualityFilterAsCharts(): void
    {
        TableConfig::setEnvironment('n3pp_test');
        $repo = new N3ppSensorRepository($this->pdo);
        $this->createTable('n3ppDataTest', $repo->getSensorColumns());
        $this->insertRow('n3ppDataTest', 'n3pp', '2026-01-01 08:00:00', ['TempAir' => 21.0, 'Humidite' => 55.0]);
        // Bruit exclu de l'affichage : trame msp1 égarée et capteur DHT muet (0/0).
        $this->insertRow('n3ppDataTest', 'msp1', '2026-01-01 08:05:00', ['TempAir' => 18.0, 'Humidite' => 40.0]);
        $this->insertRow('n3ppDataTest', 'n3pp', '2026-01-01 08:10:00', ['TempAir' => 0.0, 'Humidite' => 0.0]);

        [$count, $rows] = $this->export($repo);
        $displayed = $repo->fetchBetween('2026-01-01 00:00:00', '2026-01-01 23:59:59');

        $this->assertSame(1, $count);
        $this->assertCount(2, $rows);
        $this->assertCount($count, $displayed);
        $this->assertSame('n3pp', $rows[1][1]);
        $this->assertSame('2026-01-01 08:00:00', $rows[1][count($rows[0]) - 1]);
    }

    public function testExportOnEmptyRangeWritesHeaderOnly(): void
    {
        TableConfig::setEnvironment('msp_test');
        $repo = new MspSensorRepository($this->pdo);
        $this->createTable('msp1DataTest', $repo->getSensorColumns());

        [$count, $rows] = $this->export($repo);

        $this->assertSame(0, $count);
        $this->assertCount(1, $rows);
        $this->assertSame('id', $rows[0][0]);
    }
}
