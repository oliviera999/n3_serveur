<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Domain\EnergieSensorData;
use App\Repository\EnergieSensorRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\EnergieSqliteSchema;

/**
 * EnergieSensorRepository sur SQLite en mémoire : insertion complète, choix de la
 * table via TableConfig (prod / energie_test), lectures héritées et export CSV.
 */
final class EnergieSensorRepositoryTest extends TestCase
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
        $this->repo = new EnergieSensorRepository($this->pdo);
        TableConfig::setEnvironment('energie_test');
    }

    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
        parent::tearDown();
    }

    private function sample(string $version = '0.3.1'): EnergieSensorData
    {
        return new EnergieSensorData(
            sensor: 'energie',
            version: $version,
            panneauV: 18.4,
            panneauI: 1.2,
            panneauP: 22.08,
            panneauImax: 1.5,
            batterieV: 12.8,
            batterieVmin: 12.7,
            batterieI: -0.5,
            batterieImin: -0.9,
            batterieImax: 0.1,
            batterieP: -6.4,
            batterieVadc: 12.75,
            consoV: 12.6,
            consoI: 0.48,
            consoP: 6.05,
            consoImax: 0.61,
            energiePanneauWh: 0.0613,
            energieConsoWh: 0.0168,
            batterieAh: -1.25,
            batterieSoc: 77.0,
            inaStatus: 0x107,
            i2cErreurs: 2,
            rssi: -63,
            freeHeap: 180000,
            bootCount: 5,
            uptime: 4_000_000_000,
        );
    }

    public function testSensorColumnsCoverEveryNumericField(): void
    {
        $this->assertSame([
            'PanneauV', 'PanneauI', 'PanneauP', 'PanneauImax',
            'BatterieV', 'BatterieVmin', 'BatterieI', 'BatterieImin', 'BatterieImax', 'BatterieP', 'BatterieVadc',
            'ConsoV', 'ConsoI', 'ConsoP', 'ConsoImax',
            'EnergiePanneauWh', 'EnergieConsoWh', 'BatterieAh', 'BatterieSoc',
            'InaStatus', 'I2cErreurs', 'Rssi', 'FreeHeap', 'BootCount', 'Uptime',
        ], $this->repo->getSensorColumns());
    }

    public function testInsertWritesEveryColumnInTestTable(): void
    {
        $this->repo->insert($this->sample());

        $row = $this->repo->getLatest();
        $this->assertNotNull($row);
        $this->assertSame('energie', $row['sensor']);
        $this->assertSame('0.3.1', $row['version']);
        $this->assertEqualsWithDelta(-0.5, (float) $row['BatterieI'], 1e-9);
        $this->assertEqualsWithDelta(0.0168, (float) $row['EnergieConsoWh'], 1e-9);
        $this->assertSame(0x107, (int) $row['InaStatus']);
        $this->assertSame(4_000_000_000, (int) $row['Uptime'], 'Uptime BIGINT (> 2^31)');
        foreach ($this->repo->getSensorColumns() as $col) {
            $this->assertNotNull($row[$col], "colonne {$col} renseignée");
        }

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM energieData')->fetchColumn();
        $this->assertSame(0, $count, 'energie_test ne doit jamais écrire dans la table prod');
    }

    public function testProdEnvironmentUsesProdTable(): void
    {
        TableConfig::setEnvironment('prod');
        $this->repo->insert($this->sample('prod-1'));

        $this->assertSame('prod-1', $this->repo->getFirmwareVersion());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM energieData')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM energieDataTest')->fetchColumn());
    }

    public function testNullFieldsAreStoredAsNull(): void
    {
        $this->repo->insert(new EnergieSensorData(sensor: 'energie', version: '0.3.1', batterieV: 12.5));

        $row = $this->repo->getLatest();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(12.5, (float) $row['BatterieV'], 1e-9);
        $this->assertNull($row['PanneauV']);
        $this->assertNull($row['Uptime']);
    }

    public function testFetchBetweenAndCountsUseReadingTime(): void
    {
        $this->repo->insert($this->sample());
        $now = date('Y-m-d H:i:s');

        $rows = $this->repo->fetchBetween(date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 60));
        $this->assertCount(1, $rows);
        $this->assertSame(1, $this->repo->countReadingsToday());
        $this->assertNotNull($this->repo->getLastReadingDate());
        $this->assertLessThanOrEqual($now, (string) $this->repo->getFirstReadingDate());
    }

    public function testExportCsvWritesHeaderAndRows(): void
    {
        $this->repo->insert($this->sample());
        $file = tempnam(sys_get_temp_dir(), 'energie_csv_');
        $this->assertIsString($file);

        try {
            $count = $this->repo->exportCsv(
                date('Y-m-d H:i:s', time() - 60),
                date('Y-m-d H:i:s', time() + 60),
                $file
            );
            $lines = file($file, FILE_IGNORE_NEW_LINES);
        } finally {
            @unlink($file);
        }

        $this->assertSame(1, $count);
        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);
        $header = str_getcsv($lines[0], ',', '"', '\\');
        $this->assertSame('id', $header[0]);
        $this->assertContains('BatterieSoc', $header);
        $this->assertSame('reading_time', $header[count($header) - 1]);
    }

    public function testExportCsvOnEmptyRangeWritesHeaderOnly(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'energie_csv_');
        $this->assertIsString($file);

        try {
            $count = $this->repo->exportCsv('2020-01-01 00:00:00', '2020-01-02 00:00:00', $file);
            $lines = file($file, FILE_IGNORE_NEW_LINES);
        } finally {
            @unlink($file);
        }

        $this->assertSame(0, $count);
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);
    }
}
