<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\IotDeviceRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class IotDeviceRepositoryTest extends TestCase
{
    private PDO $pdo;
    private IotDeviceRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE iotDevices (
                device_id TEXT PRIMARY KEY,
                family TEXT NOT NULL,
                board TEXT,
                label TEXT NOT NULL,
                env TEXT NOT NULL DEFAULT \'prod\',
                page_key TEXT,
                page_url TEXT,
                live_module_id TEXT,
                live_health_url TEXT,
                active INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 1000,
                notes TEXT,
                created_at TEXT,
                updated_at TEXT,
                updated_by TEXT
            )'
        );
        $this->repo = new IotDeviceRepository($this->pdo);
        // Évite ensureSchema() MySQL (ON DUPLICATE KEY) sur SQLite mémoire.
        $ref = new \ReflectionProperty(IotDeviceRepository::class, 'schemaEnsured');
        $ref->setAccessible(true);
        $ref->setValue($this->repo, true);
    }

    private function insertRow(string $id, string $family, string $label, bool $withLive = true): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO iotDevices (device_id, family, label, env, live_module_id, live_health_url, active, sort_order)
             VALUES (:id, :family, :label, \'prod\', :live_id, :live_url, 1, 10)'
        );
        $stmt->execute([
            ':id' => $id,
            ':family' => $family,
            ':label' => $label,
            ':live_id' => $withLive ? $id . '-live' : null,
            ':live_url' => $withLive ? '/msp1/api/realtime/system/health' : null,
        ]);
    }

    public function testFindAndLiveFilter(): void
    {
        $this->insertRow('n3-msp-99', 'msp1', 'Test MSP', true);
        $this->insertRow('n3-pgl-99', 'pgl', 'PGL sans live', false);

        $found = $this->repo->findByDeviceId('n3-msp-99');
        $this->assertNotNull($found);
        $this->assertSame('msp1', $found['family']);

        $live = $this->repo->findLiveDevices();
        $this->assertCount(1, $live);
        $this->assertSame('n3-msp-99', $live[0]['device_id']);

        $all = $this->repo->findAll(false);
        $this->assertCount(2, $all);
    }

    public function testSetActiveAndDelete(): void
    {
        $this->insertRow('n3-pgl-99', 'pgl', 'PGL test', false);
        $this->repo->setActive('n3-pgl-99', false, 'admin');
        $row = $this->repo->findByDeviceId('n3-pgl-99');
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['active']);

        $this->repo->delete('n3-pgl-99');
        $this->assertNull($this->repo->findByDeviceId('n3-pgl-99'));
    }

    public function testFamiliesWhitelist(): void
    {
        $this->assertContains('msp1', IotDeviceRepository::FAMILIES);
        $this->assertContains('energie', IotDeviceRepository::FAMILIES);
        $this->assertContains('ffp3', IotDeviceRepository::FAMILIES);
    }
}
