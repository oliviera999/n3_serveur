<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\BoardRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de BoardRepository sur une base SQLite en memoire.
 *
 * Note : updateLastRequest() utilise NOW() (fonction MySQL absente de SQLite)
 * et n'est donc pas couvert ici (verifie en integration MySQL).
 */
class BoardRepositoryTest extends TestCase
{
    private PDO $pdo;
    private BoardRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE Boards (
            board TEXT PRIMARY KEY,
            last_request TEXT
        )');
        // ffp3Outputs est une table autorisee par TableValidator::OUTPUT_TABLES.
        $this->pdo->exec('CREATE TABLE ffp3Outputs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            board TEXT,
            name TEXT
        )');

        $this->repo = new BoardRepository($this->pdo);
    }

    public function testCreateThenFindByName(): void
    {
        $this->assertTrue($this->repo->create('esp32-a'));

        $row = $this->repo->findByName('esp32-a');
        $this->assertNotNull($row);
        $this->assertSame('esp32-a', $row['board']);
    }

    public function testFindByNameReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->repo->findByName('inconnue'));
    }

    public function testExistsReflectsPresence(): void
    {
        $this->assertFalse($this->repo->exists('esp32-b'));
        $this->repo->create('esp32-b');
        $this->assertTrue($this->repo->exists('esp32-b'));
    }

    public function testFormatTimestampViaFindByName(): void
    {
        // last_request est stocké en heure serveur (Europe/Paris) et affiché en heure
        // de Casablanca (heure locale réelle du projet). En été : Paris UTC+2,
        // Casablanca UTC+1 → -1 h (08:30 Paris = 07:30 Casablanca).
        $_ENV['APP_TIMEZONE'] = 'Europe/Paris';
        $this->pdo->exec("INSERT INTO Boards (board, last_request) VALUES ('esp32-c', '2024-07-15 08:30:00')");

        $row = $this->repo->findByName('esp32-c');
        $this->assertSame('15/07/2024 07:30:00', $row['last_request']);
    }

    public function testFindActiveForEnvironmentReturnsOnlyBoardsWithNamedOutputs(): void
    {
        $this->repo->create('esp32-active');
        $this->repo->create('esp32-idle');
        // Une board active a au moins un output avec un name non vide.
        $this->pdo->exec("INSERT INTO ffp3Outputs (board, name) VALUES ('esp32-active', 'pompe')");
        $this->pdo->exec("INSERT INTO ffp3Outputs (board, name) VALUES ('esp32-idle', '')");

        $active = $this->repo->findActiveForEnvironment('ffp3Outputs');

        $this->assertCount(1, $active);
        $this->assertSame('esp32-active', $active[0]['board']);
    }

    public function testFindActiveForEnvironmentRejectsInvalidTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->findActiveForEnvironment('Boards; DROP TABLE Boards');
    }
}
