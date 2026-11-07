<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\HeartbeatMonitorRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de HeartbeatMonitorRepository sur SQLite en mémoire.
 *
 * Vérifie la lecture de la dernière date de heartbeat (MAX(reading_time)) et la
 * whitelist stricte des tables (toutes familles), garde-fou contre l'injection.
 */
final class HeartbeatMonitorRepositoryTest extends TestCase
{
    private PDO $pdo;
    private HeartbeatMonitorRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->repo = new HeartbeatMonitorRepository($this->pdo);
    }

    private function createTable(string $table): void
    {
        // Schémas réels : ffp3Heartbeat* date ses lignes dans `timestamp`, n3pp/msp1 dans `reading_time`.
        $column = HeartbeatMonitorRepository::timeColumnFor($table);
        $this->pdo->exec("CREATE TABLE `{$table}` (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            `{$column}` TEXT
        )");
    }

    /**
     * Incident du 06/11/2026 : « Unknown column 'reading_time' » sur ffp3Heartbeat à chaque passage
     * horaire — la supervision heartbeat FFP3 était aveugle. La colonne dépend de la famille.
     */
    public function testFfp3TableUsesTimestampColumn(): void
    {
        self::assertSame('timestamp', HeartbeatMonitorRepository::timeColumnFor('ffp3Heartbeat'));
        self::assertSame('timestamp', HeartbeatMonitorRepository::timeColumnFor('ffp3HeartbeatS3'));
        self::assertSame('reading_time', HeartbeatMonitorRepository::timeColumnFor('n3ppHeartbeat'));
        self::assertSame('reading_time', HeartbeatMonitorRepository::timeColumnFor('msp1HeartbeatTest'));

        $this->createTable('ffp3Heartbeat');
        $this->pdo->exec("INSERT INTO ffp3Heartbeat (`timestamp`) VALUES ('2026-11-06 16:39:58')");
        $this->pdo->exec("INSERT INTO ffp3Heartbeat (`timestamp`) VALUES ('2026-11-06 16:40:27')");

        self::assertSame('2026-11-06 16:40:27', $this->repo->getLastHeartbeatDate('ffp3Heartbeat'));
        self::assertSame(['2026-11-06 16:40:27', '2026-11-06 16:39:58'], $this->repo->getRecentHeartbeatDates('ffp3Heartbeat'));
    }

    public function testReturnsNullWhenTableEmpty(): void
    {
        $this->createTable('n3ppHeartbeat');

        self::assertNull($this->repo->getLastHeartbeatDate('n3ppHeartbeat'));
    }

    public function testReturnsMostRecentReadingTime(): void
    {
        $this->createTable('msp1Heartbeat');
        $this->pdo->exec("INSERT INTO msp1Heartbeat (reading_time) VALUES ('2026-06-24 09:00:00')");
        $this->pdo->exec("INSERT INTO msp1Heartbeat (reading_time) VALUES ('2026-06-24 11:30:00')");
        $this->pdo->exec("INSERT INTO msp1Heartbeat (reading_time) VALUES ('2026-06-24 10:00:00')");

        self::assertSame('2026-06-24 11:30:00', $this->repo->getLastHeartbeatDate('msp1Heartbeat'));
    }

    public function testRejectsTableOutsideWhitelist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->getLastHeartbeatDate('evil_table; DROP TABLE users');
    }

    public function testWhitelistCoversAllThreeFamilies(): void
    {
        $tables = HeartbeatMonitorRepository::ALLOWED_TABLES;
        self::assertContains('ffp3Heartbeat', $tables);
        self::assertContains('n3ppHeartbeat', $tables);
        self::assertContains('msp1Heartbeat', $tables);
        // Variantes de test présentes aussi.
        self::assertContains('n3ppHeartbeatTest', $tables);
        self::assertContains('msp1HeartbeatTest', $tables);
    }
}
