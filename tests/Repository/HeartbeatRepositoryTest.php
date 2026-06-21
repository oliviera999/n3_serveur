<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Repository\HeartbeatRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de HeartbeatRepository sur SQLite en memoire.
 */
class HeartbeatRepositoryTest extends TestCase
{
    private PDO $pdo;
    private string $table;
    private HeartbeatRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        TableConfig::setEnvironment('prod');
        $this->table = TableConfig::getHeartbeatTable(); // 'ffp3Heartbeat' (whitelist)

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE `{$this->table}` (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uptime INTEGER,
            freeHeap INTEGER,
            minHeap INTEGER,
            reboots INTEGER
        )");

        $this->repo = new HeartbeatRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        TableConfig::setEnvironment('prod');
    }

    public function testInsertPersistsRow(): void
    {
        $this->repo->insert(123456, 90000, 45000, 3);

        $row = $this->pdo
            ->query("SELECT uptime, freeHeap, minHeap, reboots FROM `{$this->table}`")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertSame(123456, (int) $row['uptime']);
        $this->assertSame(90000, (int) $row['freeHeap']);
        $this->assertSame(45000, (int) $row['minHeap']);
        $this->assertSame(3, (int) $row['reboots']);
    }

    public function testTargetTableIsWhitelisted(): void
    {
        // La table issue de l'environnement courant doit etre dans la whitelist
        // du repository (defense contre une mauvaise config d'environnement).
        $this->assertContains($this->table, [
            'ffp3Heartbeat',
            'ffp3Heartbeat2',
            'ffp3Heartbeat3',
            'ffp3HeartbeatS3',
            'ffp3HeartbeatS3Test',
        ]);
    }
}
