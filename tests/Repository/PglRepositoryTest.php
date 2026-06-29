<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\PglConfig;
use App\Repository\PglRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de PglRepository (module Poissonglouton) sur SQLite en memoire.
 *
 * Couvre les methodes portables :
 *  - insertEvent / getTotalCount / getLatestEvent ;
 *  - getEventsSince / getEventsBetween / countEventsBetween (filtrage temporel) ;
 *  - getRunningTotalBefore (somme cumulee) ;
 *  - getMaxDeviceEventId / getFirstEventDate / getLastEventDate ;
 *  - heartbeat (insertHeartbeat / getLastHeartbeatDate) ;
 *  - getSystemHealth (logique online/offline calculee en PHP).
 *
 * NON testees ici car dependantes de fonctions MySQL absentes de SQLite :
 *  - getHourlyStats()    -> DATE_FORMAT / DATE_SUB / NOW()
 *  - getDailyStats()     -> DATE / DATE_SUB / CURDATE()
 *  - countEventsToday()  -> CURDATE()
 *  - insertEventIdempotent() -> "INSERT IGNORE" (syntaxe MySQL, SQLite utilise INSERT OR IGNORE)
 *  Ces methodes relevent de la suite d'integration (MySQL reel).
 */
class PglRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PglRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE pglEvents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            board TEXT, event_time TEXT, count_delta INTEGER, sensor_mode TEXT,
            is_tandem_validated INTEGER, battery_v REAL, rssi INTEGER, fw_version TEXT,
            device_event_id INTEGER
        )');
        $this->pdo->exec('CREATE TABLE pglHeartbeat (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uptime INTEGER, freeHeap INTEGER, minHeap INTEGER, reboots INTEGER,
            rssi INTEGER, sensor TEXT, version TEXT, sensors_present INTEGER,
            reading_time TEXT
        )');

        $this->repo = new PglRepository($this->pdo);
    }

    private function seedEvent(string $time, int $delta, int $deviceEventId = 0): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pglEvents
                (board, event_time, count_delta, sensor_mode, is_tandem_validated,
                 battery_v, rssi, fw_version, device_event_id)
             VALUES (:b, :t, :d, :m, 1, 3.7, -60, :v, :id)'
        );
        $stmt->execute([
            ':b' => PglConfig::DEFAULT_BOARD_ID,
            ':t' => $time,
            ':d' => $delta,
            ':m' => 'auto',
            ':v' => 'fw1',
            ':id' => $deviceEventId,
        ]);
    }

    public function testInsertEventAndTotalCount(): void
    {
        $this->repo->insertEvent(PglConfig::DEFAULT_BOARD_ID, '2026-06-01 10:00:00', 3, 'auto', true, 3.9, -50, 'fw1');
        $this->repo->insertEvent(PglConfig::DEFAULT_BOARD_ID, '2026-06-01 11:00:00', 2, 'auto', false, null, null, 'fw1');

        $this->assertSame(5, $this->repo->getTotalCount());
    }

    public function testGetTotalCountIsZeroWhenEmpty(): void
    {
        $this->assertSame(0, $this->repo->getTotalCount());
    }

    public function testGetLatestEventReturnsMostRecent(): void
    {
        $this->seedEvent('2026-06-01 10:00:00', 1);
        $this->seedEvent('2026-06-01 12:00:00', 4);

        $latest = $this->repo->getLatestEvent();
        $this->assertNotNull($latest);
        $this->assertSame('2026-06-01 12:00:00', $latest['event_time']);
        $this->assertSame(4, (int) $latest['count_delta']);
    }

    public function testGetLatestEventReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->repo->getLatestEvent());
    }

    public function testGetEventsSinceFiltersStrictlyAfter(): void
    {
        $this->seedEvent('2026-06-01 09:00:00', 1);
        $this->seedEvent('2026-06-01 10:00:00', 2);
        $this->seedEvent('2026-06-01 11:00:00', 3);

        $rows = $this->repo->getEventsSince('2026-06-01 10:00:00');

        $this->assertCount(1, $rows);
        $this->assertSame('2026-06-01 11:00:00', $rows[0]['event_time']);
    }

    public function testGetEventsBetweenIsInclusiveAndOrdered(): void
    {
        $this->seedEvent('2026-06-01 08:00:00', 1);
        $this->seedEvent('2026-06-01 10:00:00', 2);
        $this->seedEvent('2026-06-01 12:00:00', 3);

        $rows = $this->repo->getEventsBetween('2026-06-01 10:00:00', '2026-06-01 12:00:00');

        $this->assertCount(2, $rows);
        $this->assertSame('2026-06-01 10:00:00', $rows[0]['event_time']);
        $this->assertSame('2026-06-01 12:00:00', $rows[1]['event_time']);
    }

    public function testCountEventsBetween(): void
    {
        $this->seedEvent('2026-06-01 10:00:00', 1);
        $this->seedEvent('2026-06-01 11:00:00', 1);
        $this->seedEvent('2026-06-02 10:00:00', 1);

        $count = $this->repo->countEventsBetween('2026-06-01 00:00:00', '2026-06-01 23:59:59');
        $this->assertSame(2, $count);
    }

    public function testGetRunningTotalBefore(): void
    {
        $this->seedEvent('2026-06-01 10:00:00', 5);
        $this->seedEvent('2026-06-01 11:00:00', 7);
        $this->seedEvent('2026-06-01 12:00:00', 9);

        // Strictement avant 12:00 -> 5 + 7.
        $this->assertSame(12, $this->repo->getRunningTotalBefore('2026-06-01 12:00:00'));
    }

    public function testGetMaxDeviceEventId(): void
    {
        $this->seedEvent('2026-06-01 10:00:00', 1, 4);
        $this->seedEvent('2026-06-01 11:00:00', 1, 9);

        $this->assertSame(9, $this->repo->getMaxDeviceEventId());
    }

    public function testGetMaxDeviceEventIdIsZeroWhenEmpty(): void
    {
        $this->assertSame(0, $this->repo->getMaxDeviceEventId());
    }

    public function testGetFirstAndLastEventDate(): void
    {
        $this->seedEvent('2026-06-01 10:00:00', 1);
        $this->seedEvent('2026-06-03 10:00:00', 1);

        $this->assertSame('2026-06-01 10:00:00', $this->repo->getFirstEventDate());
        $this->assertSame('2026-06-03 10:00:00', $this->repo->getLastEventDate());
    }

    public function testGetFirstEventDateNullWhenEmpty(): void
    {
        $this->assertNull($this->repo->getFirstEventDate());
        $this->assertNull($this->repo->getLastEventDate());
    }

    public function testInsertHeartbeatAndLastHeartbeatDate(): void
    {
        $this->pdo->exec("INSERT INTO pglHeartbeat (uptime, reading_time) VALUES (10, '2026-06-01 09:00:00')");
        $this->pdo->exec("INSERT INTO pglHeartbeat (uptime, reading_time) VALUES (20, '2026-06-01 10:00:00')");

        $this->assertSame('2026-06-01 10:00:00', $this->repo->getLastHeartbeatDate());

        // insertHeartbeat ne renseigne pas reading_time (DEFAULT en BDD reelle) :
        // on verifie simplement qu'une ligne est ajoutee.
        $this->repo->insertHeartbeat(100, 2000, 1000, 1, -55, 'esp', 'fw1', 7);
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM pglHeartbeat')->fetchColumn());
    }

    public function testGetLastHeartbeatDateNullWhenEmpty(): void
    {
        $this->assertNull($this->repo->getLastHeartbeatDate());
    }

    public function testGetSystemHealthOfflineWhenNoData(): void
    {
        $health = $this->repo->getSystemHealth(PglConfig::ONLINE_THRESHOLD_SECONDS);

        $this->assertFalse($health['online']);
        $this->assertNull($health['last_reading']);
        $this->assertNull($health['source']);
    }

    public function testGetSystemHealthOnlineFromRecentEvent(): void
    {
        $recent = date('Y-m-d H:i:s', time() - 10);
        $this->seedEvent($recent, 1);

        $health = $this->repo->getSystemHealth(300);

        $this->assertTrue($health['online']);
        $this->assertSame('event', $health['source']);
        $this->assertNotNull($health['last_reading_ago_seconds']);
        $this->assertLessThan(300, $health['last_reading_ago_seconds']);
    }

    public function testGetSystemHealthOfflineFromOldEvent(): void
    {
        $old = date('Y-m-d H:i:s', time() - 10_000);
        $this->seedEvent($old, 1);

        $health = $this->repo->getSystemHealth(300);

        $this->assertFalse($health['online']);
        $this->assertSame('event', $health['source']);
    }

    public function testGetSystemHealthPicksMostRecentSource(): void
    {
        $heartbeat = date('Y-m-d H:i:s', time() - 5);
        $event = date('Y-m-d H:i:s', time() - 100);
        $this->seedEvent($event, 1);
        $stmt = $this->pdo->prepare('INSERT INTO pglHeartbeat (uptime, reading_time) VALUES (1, :t)');
        $stmt->execute([':t' => $heartbeat]);

        $health = $this->repo->getSystemHealth(300);

        $this->assertSame('heartbeat', $health['source']);
        $this->assertTrue($health['online']);
    }
}
