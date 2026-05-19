<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\SensorStatisticsService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Couvre la nouvelle méthode `aggregateMany()` qui consolide 28 requêtes (4×7) en une seule.
 * Utilise une base SQLite en mémoire pour ne pas dépendre du MySQL local.
 *
 * Note : la table est créée avec le nom retourné par TableConfig::getDataTable() ('ffp3Data' par défaut).
 */
final class SensorStatisticsServiceAggregateManyTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite non disponible : test agrégat ignoré.');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // SQLite n'a pas STDDEV natif : on enregistre une UDF agrégat (population stddev).
        $this->pdo->sqliteCreateAggregate(
            'STDDEV',
            static function (?array $context, int $rows, $value): array {
                $context ??= ['n' => 0, 'sum' => 0.0, 'sqSum' => 0.0];
                if ($value !== null && is_numeric($value)) {
                    $v = (float) $value;
                    $context['n']++;
                    $context['sum'] += $v;
                    $context['sqSum'] += $v * $v;
                }
                return $context;
            },
            static function (?array $context, int $rows): ?float {
                if (!is_array($context) || ($context['n'] ?? 0) < 1) {
                    return null;
                }
                $n = $context['n'];
                $mean = $context['sum'] / $n;
                $var = ($context['sqSum'] / $n) - ($mean * $mean);
                return $var > 0 ? sqrt($var) : 0.0;
            }
        );

        $this->pdo->exec(
            'CREATE TABLE ffp3Data ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'TempAir REAL, Humidite REAL, TempEau REAL,'
                . 'EauPotager REAL, EauAquarium REAL, EauReserve REAL, Luminosite REAL,'
                . 'reading_time TEXT'
                . ')'
        );

        // Jeu de données simple : 3 lignes dans la fenêtre, 1 ligne hors fenêtre.
        $stmt = $this->pdo->prepare(
            'INSERT INTO ffp3Data (TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve, Luminosite, reading_time)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $rows = [
            [20.0, 50.0, 22.0, 100.0, 120.0, 110.0, 500.0, '2026-05-19 10:00:00'],
            [22.0, 55.0, 23.0, 102.0, 122.0, 112.0, 600.0, '2026-05-19 11:00:00'],
            [24.0, 60.0, 24.0, 104.0, 124.0, 114.0, 700.0, '2026-05-19 12:00:00'],
            // Hors fenêtre :
            [99.0, 99.0, 99.0, 999.0, 999.0, 999.0, 9999.0, '2026-05-18 12:00:00'],
        ];
        foreach ($rows as $r) {
            $stmt->execute($r);
        }
    }

    public function testAggregateManyReturnsMinMaxAvgPerColumn(): void
    {
        $service = new SensorStatisticsService($this->pdo);

        $result = $service->aggregateMany(
            ['TempAir', 'Humidite', 'TempEau'],
            '2026-05-19 00:00:00',
            '2026-05-19 23:59:59'
        );

        $this->assertArrayHasKey('TempAir', $result);
        $this->assertEqualsWithDelta(20.0, $result['TempAir']['min'], 0.01);
        $this->assertEqualsWithDelta(24.0, $result['TempAir']['max'], 0.01);
        $this->assertEqualsWithDelta(22.0, $result['TempAir']['avg'], 0.01);
        $this->assertNotNull($result['TempAir']['stddev']);

        $this->assertEqualsWithDelta(55.0, $result['Humidite']['avg'], 0.01);
        $this->assertEqualsWithDelta(23.0, $result['TempEau']['avg'], 0.01);
    }

    public function testAggregateManyFiltersOutNonWhitelistedColumns(): void
    {
        $service = new SensorStatisticsService($this->pdo);
        $result = $service->aggregateMany(
            ['TempAir', 'BogusColumn', '__not_safe__'],
            '2026-05-19 00:00:00',
            '2026-05-19 23:59:59'
        );

        $this->assertArrayHasKey('TempAir', $result);
        $this->assertArrayNotHasKey('BogusColumn', $result);
        $this->assertArrayNotHasKey('__not_safe__', $result);
    }

    public function testAggregateManyEmptyColumnsReturnsEmpty(): void
    {
        $service = new SensorStatisticsService($this->pdo);
        $this->assertSame([], $service->aggregateMany([], '2026-05-19', '2026-05-19'));
    }

    public function testAggregateManyNoMatchingRowsReturnsNulls(): void
    {
        $service = new SensorStatisticsService($this->pdo);
        $result = $service->aggregateMany(
            ['TempAir'],
            '2030-01-01 00:00:00',
            '2030-01-02 00:00:00'
        );
        $this->assertNull($result['TempAir']['min']);
        $this->assertNull($result['TempAir']['max']);
        $this->assertNull($result['TempAir']['avg']);
        $this->assertNull($result['TempAir']['stddev']);
    }
}
