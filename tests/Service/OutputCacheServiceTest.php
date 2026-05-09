<?php

namespace Tests\Service;

use App\Service\OutputCacheService;
use PDO;
use PHPUnit\Framework\TestCase;

class OutputCacheServiceTest extends TestCase
{
    private PDO $pdo;
    private OutputCacheService $service;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        // Configure environment
        putenv('ENV=prod');
        $_ENV['ENV'] = 'prod';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ffp3Outputs (
            gpio INTEGER PRIMARY KEY,
            state INTEGER
        )');

        // Insert test data
        $this->pdo->exec('INSERT INTO ffp3Outputs (gpio, state) VALUES (16, 1)');
        $this->pdo->exec('INSERT INTO ffp3Outputs (gpio, state) VALUES (18, 0)');
        $this->pdo->exec('INSERT INTO ffp3Outputs (gpio, state) VALUES (110, 1)');

        $this->pdo->exec('CREATE TABLE ffp3OtaTrigger (
            env TEXT PRIMARY KEY,
            pending INTEGER NOT NULL DEFAULT 0
        )');

        $this->service = new OutputCacheService($this->pdo);
    }

    protected function tearDown(): void
    {
        // Invalider le cache après chaque test
        $this->service->invalidateCache();
    }

    public function testGetOutputsStateReturnsEmptyArrayForEmptyList(): void
    {
        $result = $this->service->getOutputsState($this->pdo, []);
        
        $this->assertSame([], $result);
    }

    public function testGetOutputsStateReturnsCorrectStates(): void
    {
        $result = $this->service->getOutputsState($this->pdo, [16, 18]);
        
        $this->assertArrayHasKey('16', $result);
        $this->assertArrayHasKey('18', $result);
    }

    public function testInvalidateCacheClearsCache(): void
    {
        // Cache supprimé (v5.x) : invalidateCache est un no-op, getCacheStats retourne toujours valid=false
        $this->service->getOutputsState($this->pdo, [16]);
        $this->service->invalidateCache();
        $stats = $this->service->getCacheStats();
        $this->assertFalse($stats['valid']);
        $this->assertSame(0, $stats['cached_items']);
    }

    public function testGetCacheStatsReturnsExpectedStructure(): void
    {
        $stats = $this->service->getCacheStats();
        
        $this->assertArrayHasKey('valid', $stats);
        $this->assertArrayHasKey('environment', $stats);
        $this->assertArrayHasKey('age_seconds', $stats);
        $this->assertArrayHasKey('ttl_seconds', $stats);
        $this->assertArrayHasKey('cached_items', $stats);
    }

    public function testGetOutputsStateAlwaysReadsFromBdd(): void
    {
        // Cache supprimé (v5.x) : chaque appel lit la BDD, getCacheStats reste valid=false
        $result = $this->service->getOutputsState($this->pdo, [16, 18]);
        $this->assertArrayHasKey('16', $result);
        $this->assertArrayHasKey('18', $result);
        $stats = $this->service->getCacheStats();
        $this->assertFalse($stats['valid']);
        $this->assertSame(0, $stats['cached_items']);
    }

    public function testTriggerOtaCheckPersistedThenConsumedOnce(): void
    {
        $this->service->setTriggerOtaCheckRequested();
        $first = $this->service->getOutputsState($this->pdo, [16]);
        $this->assertTrue($first['triggerOtaCheck'] ?? false);

        $second = $this->service->getOutputsState($this->pdo, [16]);
        $this->assertArrayNotHasKey('triggerOtaCheck', $second);
    }

    public function testReadOnlyStateDoesNotConsumeOtaTrigger(): void
    {
        $this->service->setTriggerOtaCheckRequested();

        $readOnly = $this->service->getOutputsState($this->pdo, [16], true, false);
        $this->assertArrayNotHasKey('triggerOtaCheck', $readOnly);

        $firmwarePoll = $this->service->getOutputsState($this->pdo, [16]);
        $this->assertTrue($firmwarePoll['triggerOtaCheck'] ?? false);

        $afterConsume = $this->service->getOutputsState($this->pdo, [16]);
        $this->assertArrayNotHasKey('triggerOtaCheck', $afterConsume);
    }
}
