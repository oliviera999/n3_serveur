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
        $this->pdo->exec("CREATE TABLE `{$table}` (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reading_time TEXT
        )");
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
