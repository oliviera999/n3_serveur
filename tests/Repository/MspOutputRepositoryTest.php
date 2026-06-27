<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Repository\BoardRepository;
use App\Repository\MspOutputRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de MspOutputRepository sur SQLite en memoire.
 *
 * Exerce aussi la logique commune d'AbstractOutputRepository (etat firmware,
 * acquittement one-shot des GPIO 108/109/110, lecture/ecriture des parametres).
 *
 * updateLastRequest() (BoardRepository) utilise NOW() (MySQL), absent de SQLite :
 * on le neutralise via un double de test pour pouvoir tester getStateForFirmware.
 */
class MspOutputRepositoryTest extends TestCase
{
    private PDO $pdo;
    private string $table;
    private MspOutputRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        TableConfig::setEnvironment('prod');
        $this->table = TableConfig::getMspOutputsTable(); // 'msp1Outputs'

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE `{$this->table}` (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            board INTEGER,
            gpio INTEGER,
            state TEXT
        )");
        $this->pdo->exec('CREATE TABLE Boards (board TEXT PRIMARY KEY, last_request TEXT)');

        // Double : neutralise updateLastRequest() (NOW() indisponible en SQLite).
        $boardRepo = new class ($this->pdo) extends BoardRepository {
            public function updateLastRequest(string $board): bool
            {
                return true;
            }
        };

        $this->repo = new MspOutputRepository($this->pdo, $boardRepo);
    }

    protected function tearDown(): void
    {
        TableConfig::setEnvironment('prod');
    }

    private function insertOutput(string $name, int $board, int $gpio, string $state): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->table}` (name, board, gpio, state) VALUES (:name, :board, :gpio, :state)"
        );
        $stmt->execute([':name' => $name, ':board' => $board, ':gpio' => $gpio, ':state' => $state]);
    }

    public function testGetAllForBoardReturnsRowsOrderedById(): void
    {
        $this->insertOutput('relais1', 1, 2, '1');
        $this->insertOutput('relais2', 1, 15, '0');
        $this->insertOutput('autreBoard', 2, 16, '1');

        $rows = $this->repo->getAllForBoard(1);

        $this->assertCount(2, $rows);
        $this->assertSame('relais1', $rows[0]['name']);
        $this->assertSame('relais2', $rows[1]['name']);
    }

    public function testGetStateForFirmwareReturnsGpioStateMap(): void
    {
        $this->insertOutput('relais1', 1, 2, '1');
        $this->insertOutput('relais2', 1, 15, '0');

        $state = $this->repo->getStateForFirmware(1);

        $this->assertEquals(['2' => '1', '15' => '0'], $state);
    }

    public function testGetStateForFirmwareAcksOneShotGpio(): void
    {
        // 110 = GPIO one-shot (reset) : doit etre repasse a 0 apres lecture.
        // NB : 108/109 ne conviennent plus pour ce test — depuis la politique de
        // notifications (v5.9.1) ils sont server-only pour MSP/N3PP (notifMode /
        // notifCategories) et donc exclus des reponses firmware.
        $this->insertOutput('oneShot', 1, 110, '1');
        // 2 = GPIO classique : reste a 1.
        $this->insertOutput('classique', 1, 2, '1');

        $state = $this->repo->getStateForFirmware(1);

        // La reponse renvoyee au firmware contient encore l'etat actif.
        $this->assertEquals(['110' => '1', '2' => '1'], $state);

        // En base, le one-shot est acquitte (remis a 0), le classique inchange.
        $after = $this->pdo
            ->query("SELECT gpio, state FROM `{$this->table}` ORDER BY gpio ASC")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('1', $after[2]);
        $this->assertSame('0', $after[110]);
    }

    public function testUpdateByGpioChangesState(): void
    {
        $this->insertOutput('relais', 1, 2, '0');

        $this->repo->updateByGpio(2, '1', 1);

        $state = $this->repo->getStateForFirmware(1);
        $this->assertEquals(['2' => '1'], $state);
    }

    public function testUpdateByNameChangesState(): void
    {
        $this->insertOutput('pompe', 1, 2, '0');

        $this->repo->updateByName('pompe', '1', 1);

        $row = $this->pdo
            ->query("SELECT state FROM `{$this->table}` WHERE name = 'pompe'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('1', $row['state']);
    }

    public function testGetParametersForBoardMapsGpioToParamNames(): void
    {
        // 100 => 'mail', 107 => 'FreqWakeUp' (cf. MspGpioMap)
        $this->insertOutput('p_mail', 1, 100, '1');
        $this->insertOutput('p_freq', 1, 107, '5');

        $params = $this->repo->getParametersForBoard(1);

        $this->assertSame('1', $params['mail']);
        $this->assertSame('5', $params['FreqWakeUp']);
        // Les parametres non renseignes sont presents avec une valeur vide.
        $this->assertArrayHasKey('SeuilSec', $params);
        $this->assertSame('', $params['SeuilSec']);
    }

    public function testUpdateParameterByNameUsesReverseMap(): void
    {
        $this->insertOutput('p_mail', 1, 100, '0');

        $this->assertTrue($this->repo->updateParameterByName(1, 'mail', '1'));
        $this->assertFalse($this->repo->updateParameterByName(1, 'parametreInconnu', '1'));

        $params = $this->repo->getParametersForBoard(1);
        $this->assertSame('1', $params['mail']);
    }

    public function testBatchUpdateParametersIgnoresUnknownKeys(): void
    {
        $this->insertOutput('p_mail', 1, 100, '0');
        $this->insertOutput('p_freq', 1, 107, '0');

        $this->repo->batchUpdateParameters(1, [
            'mail' => '1',
            'FreqWakeUp' => '9',
            'inconnu' => 'x',
        ]);

        $params = $this->repo->getParametersForBoard(1);
        $this->assertSame('1', $params['mail']);
        $this->assertSame('9', $params['FreqWakeUp']);
    }

    public function testGetOutputByGpioAndBoard(): void
    {
        $this->insertOutput('reset', 1, 110, '0');

        $row = $this->repo->getOutputByGpioAndBoard(1, 110);
        $this->assertNotNull($row);
        $this->assertSame('reset', $row['name']);

        $this->assertNull($this->repo->getOutputByGpioAndBoard(1, 999));
    }
}
