<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\BoardRepository;
use App\Repository\MspSensorRepository;
use App\Repository\N3ppSensorRepository;
use App\Repository\SensorReadRepository;
use DateTimeImmutable;
use PDO;

/**
 * Lectures repositories contre MySQL avec snapshot (fenêtres courtes pour éviter charger des centaines de milliers de lignes).
 */
class SensorRepositoriesSnapshotIntegrationTest extends IntegrationDbTestCase
{
    private function pdoAndSnapshot(): PDO
    {
        $pdo = $this->createPdoOrSkip();
        $this->requireProductionSnapshotOrSkip($pdo);

        return $pdo;
    }

    public function testSensorReadRepositoryDatesAndFirmware(): void
    {
        $pdo = $this->pdoAndSnapshot();
        $repo = new SensorReadRepository($pdo);

        $last = $repo->getLastReadingDate();
        $first = $repo->getFirstReadingDate();
        self::assertNotNull($last);
        self::assertNotNull($first);
        $mn = $pdo->query('SELECT MIN(reading_time) FROM ffp3Data')->fetchColumn();
        $mx = $pdo->query('SELECT MAX(reading_time) FROM ffp3Data')->fetchColumn();
        self::assertSame((string) $mn, $first, 'getFirstReadingDate aligne sur MIN(ffp3Data)');
        self::assertSame((string) $mx, $last, 'getLastReadingDate aligne sur MAX(ffp3Data)');
        self::assertLessThanOrEqual(0, strcmp((string) $first, (string) $last), 'MIN <= MAX (format SQL datetime)');

        $version = $repo->getFirmwareVersion();
        self::assertNotSame('N/A', $version);
        self::assertNotSame('', $version);
    }

    public function testSensorReadRepositoryLastReadingsOrder(): void
    {
        $pdo = $this->pdoAndSnapshot();
        $repo = new SensorReadRepository($pdo);
        $rows = $repo->getLastReadings(2);
        self::assertCount(2, $rows);
        self::assertArrayHasKey('reading_time', $rows[0]);
        self::assertArrayHasKey('reading_time', $rows[1]);
        self::assertGreaterThanOrEqual(
            0,
            strcmp((string) $rows[0]['reading_time'], (string) $rows[1]['reading_time']),
            'ORDER BY reading_time DESC'
        );
    }

    public function testSensorReadRepositoryCountMatchesFetchBetweenShortWindow(): void
    {
        $pdo = $this->pdoAndSnapshot();
        $repo = new SensorReadRepository($pdo);
        $last = $repo->getLastReadingDate();
        self::assertNotNull($last);

        $end = new DateTimeImmutable($last);
        $start = $end->modify('-10 minutes');
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');

        $expected = $repo->countReadingsBetween($startStr, $endStr);
        $rows = $repo->fetchBetween($startStr, $endStr);
        self::assertSame($expected, count($rows), 'countReadingsBetween doit égaler le nombre de lignes fetchBetween');
        self::assertLessThan(50_000, count($rows), 'fenêtre 10 min ne doit pas exploser la mémoire');
    }

    public function testMspSensorRepositoryLatestAndShortWindow(): void
    {
        $pdo = $this->pdoAndSnapshot();
        $repo = new MspSensorRepository($pdo);

        $latest = $repo->getLatest();
        self::assertNotNull($latest);
        foreach (['sensor', 'version', 'reading_time'] as $key) {
            self::assertArrayHasKey($key, $latest);
        }

        $last = $repo->getLastReadingDate();
        self::assertNotNull($last);
        $end = new DateTimeImmutable($last);
        $startStr = $end->modify('-10 minutes')->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');
        $rows = $repo->fetchBetween($startStr, $endStr);
        self::assertNotEmpty($rows);
        self::assertLessThan(50_000, count($rows));
        foreach ($rows as $row) {
            $t = (string) ($row['reading_time'] ?? '');
            self::assertNotSame('', $t);
            self::assertLessThanOrEqual(0, strcmp($startStr, $t), 'reading_time >= debut fenetre');
            self::assertLessThanOrEqual(0, strcmp($t, $endStr), 'reading_time <= fin fenetre');
        }
    }

    public function testN3ppSensorRepositoryLatestAndShortWindow(): void
    {
        $pdo = $this->pdoAndSnapshot();
        $repo = new N3ppSensorRepository($pdo);

        $latest = $repo->getLatest();
        self::assertNotNull($latest);
        foreach (['sensor', 'version', 'reading_time'] as $key) {
            self::assertArrayHasKey($key, $latest);
        }

        $last = $repo->getLastReadingDate();
        self::assertNotNull($last);
        $end = new DateTimeImmutable($last);
        $startStr = $end->modify('-10 minutes')->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');
        $rows = $repo->fetchBetween($startStr, $endStr);
        self::assertNotEmpty($rows);
        self::assertLessThan(50_000, count($rows));
    }

    public function testBoardRepositoryActiveFfp3OutputsJoin(): void
    {
        $pdo = $this->pdoAndSnapshot();
        $repo = new BoardRepository($pdo);
        $active = $repo->findActiveForEnvironment('ffp3Outputs');
        self::assertNotEmpty($active, 'au moins une board liée à ffp3Outputs avec nom renseigné');
        foreach ($active as $row) {
            self::assertArrayHasKey('board', $row);
            self::assertIsString($row['board']);
            self::assertNotSame('', $row['board']);
        }
    }
}
