<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Repository\OutputRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class OutputRepositoryManualFeedTest extends TestCase
{
    private PDO $pdo;
    private OutputRepository $repo;
    private string $table;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        TableConfig::setEnvironment('prod');
        $this->table = TableConfig::getOutputsTable();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (method_exists($this->pdo, 'sqliteCreateFunction')) {
            $this->pdo->sqliteCreateFunction('NOW', static fn (): string => '2026-06-28 12:00:00');
        }
        $this->pdo->exec("CREATE TABLE `{$this->table}` (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            board TEXT,
            gpio INTEGER,
            name TEXT,
            state TEXT,
            requestTime TEXT,
            lastModifiedBy TEXT
        )");

        $this->repo = new OutputRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        TableConfig::setEnvironment('prod');
    }

    private function insertFeedOutput(int $gpio, string $state): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->table}` (board, gpio, name, state) VALUES ('1', :gpio, :name, :state)"
        );
        $stmt->execute([
            ':gpio' => $gpio,
            ':name' => $gpio === 108 ? 'bouffePetits' : 'bouffeGros',
            ':state' => $state,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testIncrementFeedCounterRequiresMatchingGpio(): void
    {
        $smallId = $this->insertFeedOutput(108, '4');
        $bigId = $this->insertFeedOutput(109, '8');

        $this->assertNull(
            $this->repo->incrementFeedCounter($smallId, 109),
            'Un id de GPIO 108 ne doit pas incrémenter la commande GPIO 109.'
        );

        $states = $this->pdo
            ->query("SELECT gpio, state FROM `{$this->table}` ORDER BY gpio ASC")
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame('4', $states[108]);
        $this->assertSame('8', $states[109]);

        $this->assertSame(9, $this->repo->incrementFeedCounter($bigId, 109));
    }
}
