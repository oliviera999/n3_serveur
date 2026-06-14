<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Repository\BoardRepository;
use App\Repository\MspOutputRepository;
use App\Repository\N3ppOutputRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test de la factorisation H07 : getParametersForBoard(), updateParameterByName()
 * et batchUpdateParameters() vivent dans AbstractOutputRepository et s'appuient sur
 * getParamGpioMap() de chaque sous-classe.
 *
 * Vérifie que les DEUX schémas GPIO (Msp1 et N3pp) sont correctement mappés :
 * seules les clés 104/105 diffèrent (ServoHB/ServoGD côté MSP,
 * HeureArrosage/tempsArrosage côté N3PP).
 *
 * Utilise un PDO SQLite en mémoire (même style que les autres tests repository).
 */
final class OutputParametersTest extends TestCase
{
    private PDO $pdo;
    private BoardRepository $boardRepo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // L'environnement TableConfig est un état global de processus : on ne peut pas
        // être à la fois en 'msp_test' et 'n3pp_test'. On crée donc, pour chaque module,
        // la table que son repository résout réellement et on bascule l'environnement
        // dans chaque test selon le module testé.
        TableConfig::resetRequestEnvironment();

        $this->createTable($this->mspTable());
        $this->createTable($this->n3ppTable());

        // Faux BoardRepository : updateLastRequest() utilise NOW() (non supporté SQLite).
        $this->boardRepo = new class ($this->pdo) extends BoardRepository {
            public function updateLastRequest(string $board): bool
            {
                return true;
            }
        };
    }

    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
    }

    /** Nom de table que MspOutputRepository résout en environnement de test MSP. */
    private function mspTable(): string
    {
        TableConfig::setEnvironment('msp_test');
        return TableConfig::getMspOutputsTable();
    }

    /** Nom de table que N3ppOutputRepository résout en environnement de test N3PP. */
    private function n3ppTable(): string
    {
        TableConfig::setEnvironment('n3pp_test');
        return TableConfig::getN3ppOutputsTable();
    }

    private function newMspRepo(): MspOutputRepository
    {
        TableConfig::setEnvironment('msp_test');
        return new MspOutputRepository($this->pdo, $this->boardRepo);
    }

    private function newN3ppRepo(): N3ppOutputRepository
    {
        TableConfig::setEnvironment('n3pp_test');
        return new N3ppOutputRepository($this->pdo, $this->boardRepo);
    }

    private function createTable(string $name): void
    {
        $this->pdo->exec("CREATE TABLE {$name} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            board INTEGER,
            gpio INTEGER,
            state TEXT
        )");
    }

    /**
     * Insère le jeu de paramètres GPIO 100-107 + spécifiques.
     *
     * @param array<int, string> $gpioStates gpio => state
     */
    private function seed(string $table, int $board, array $gpioStates): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$table} (name, board, gpio, state) VALUES ('p', :board, :gpio, :state)"
        );
        foreach ($gpioStates as $gpio => $state) {
            $stmt->execute([':board' => $board, ':gpio' => $gpio, ':state' => $state]);
        }
    }

    private function stateOf(string $table, int $board, int $gpio): ?string
    {
        $stmt = $this->pdo->prepare("SELECT state FROM {$table} WHERE board = :b AND gpio = :g");
        $stmt->execute([':b' => $board, ':g' => $gpio]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (string) $val;
    }

    // ── getParametersForBoard ────────────────────────────────────────────

    public function testGetParametersForBoardMspMapping(): void
    {
        $this->seed($this->mspTable(), 7, [
            100 => 'a@b.c',
            101 => '1',
            102 => '50',
            103 => '512',
            104 => '90',   // ServoHB (spécifique MSP)
            105 => '45',   // ServoGD (spécifique MSP)
            106 => '08:00',
            107 => '15',
            111 => 'auto',
        ]);

        $params = $this->newMspRepo()->getParametersForBoard(7);

        $this->assertSame('a@b.c', $params['mail']);
        $this->assertSame('1', $params['mailNotif']);
        $this->assertSame('50', $params['SeuilSec']);
        $this->assertSame('512', $params['SeuilPontDiv']);
        $this->assertSame('90', $params['ServoHB']);
        $this->assertSame('45', $params['ServoGD']);
        $this->assertSame('08:00', $params['WakeUp']);
        $this->assertSame('15', $params['FreqWakeUp']);
        $this->assertSame('auto', $params['ServoModeAuto']);

        // Le schéma MSP n'expose PAS les noms N3PP.
        $this->assertArrayNotHasKey('HeureArrosage', $params);
        $this->assertArrayNotHasKey('tempsArrosage', $params);
    }

    public function testGetParametersForBoardN3ppMapping(): void
    {
        $this->seed($this->n3ppTable(), 4, [
            100 => 'x@y.z',
            101 => '0',
            102 => '30',
            103 => '256',
            104 => '06:30',  // HeureArrosage (spécifique N3PP)
            105 => '120',    // tempsArrosage (spécifique N3PP)
            106 => '07:00',
            107 => '30',
        ]);

        $params = $this->newN3ppRepo()->getParametersForBoard(4);

        $this->assertSame('x@y.z', $params['mail']);
        $this->assertSame('0', $params['mailNotif']);
        $this->assertSame('30', $params['SeuilSec']);
        $this->assertSame('256', $params['SeuilPontDiv']);
        $this->assertSame('06:30', $params['HeureArrosage']);
        $this->assertSame('120', $params['tempsArrosage']);
        $this->assertSame('07:00', $params['WakeUp']);
        $this->assertSame('30', $params['FreqWakeUp']);

        // Le schéma N3PP n'expose PAS les noms MSP servos.
        $this->assertArrayNotHasKey('ServoHB', $params);
        $this->assertArrayNotHasKey('ServoGD', $params);
        $this->assertArrayNotHasKey('ServoModeAuto', $params);
    }

    public function testGetParametersForBoardReturnsEmptyStringForMissingGpio(): void
    {
        // Seul GPIO 100 présent : les autres clés doivent exister avec ''.
        $this->seed($this->mspTable(), 9, [100 => 'only']);

        $repo = $this->newMspRepo();
        $params = $repo->getParametersForBoard(9);

        $this->assertSame('only', $params['mail']);
        $this->assertSame('', $params['ServoHB']);
        $this->assertSame('', $params['FreqWakeUp']);
    }

    // ── updateParameterByName ────────────────────────────────────────────

    public function testUpdateParameterByNameMsp(): void
    {
        $this->seed($this->mspTable(), 7, [104 => 'old']);

        $repo = $this->newMspRepo();
        $ok = $repo->updateParameterByName(7, 'ServoHB', '120'); // -> GPIO 104

        $this->assertTrue($ok);
        $this->assertSame('120', $this->stateOf($this->mspTable(), 7, 104));
    }

    public function testUpdateParameterByNameN3ppUsesItsOwnGpioMap(): void
    {
        $this->seed($this->n3ppTable(), 4, [104 => 'old']);

        $repo = $this->newN3ppRepo();
        // 'HeureArrosage' -> GPIO 104 côté N3PP (alors que 104 = ServoHB côté MSP).
        $ok = $repo->updateParameterByName(4, 'HeureArrosage', '05:15');

        $this->assertTrue($ok);
        $this->assertSame('05:15', $this->stateOf($this->n3ppTable(), 4, 104));
    }

    public function testUpdateParameterByNameRejectsUnknownName(): void
    {
        $repo = new MspOutputRepository($this->pdo, $this->boardRepo);

        // 'HeureArrosage' n'existe pas dans le schéma MSP.
        $this->assertFalse($repo->updateParameterByName(7, 'HeureArrosage', 'x'));
        $this->assertFalse($repo->updateParameterByName(7, 'inexistant', 'x'));
    }

    // ── batchUpdateParameters ────────────────────────────────────────────

    public function testBatchUpdateParametersMsp(): void
    {
        $this->seed($this->mspTable(), 7, [
            100 => '',
            104 => '',
            105 => '',
        ]);

        $repo = $this->newMspRepo();
        $repo->batchUpdateParameters(7, [
            'mail' => 'new@mail.fr',  // GPIO 100
            'ServoHB' => '88',        // GPIO 104
            'ServoGD' => '12',        // GPIO 105
            'inexistant' => 'ignore', // ignoré
            'HeureArrosage' => 'ignore', // nom N3PP : ignoré côté MSP
        ]);

        $this->assertSame('new@mail.fr', $this->stateOf($this->mspTable(), 7, 100));
        $this->assertSame('88', $this->stateOf($this->mspTable(), 7, 104));
        $this->assertSame('12', $this->stateOf($this->mspTable(), 7, 105));
    }

    public function testBatchUpdateParametersN3ppMapping(): void
    {
        $this->seed($this->n3ppTable(), 4, [
            104 => '',
            105 => '',
        ]);

        $repo = $this->newN3ppRepo();
        $repo->batchUpdateParameters(4, [
            'HeureArrosage' => '04:00', // GPIO 104
            'tempsArrosage' => '300',   // GPIO 105
            'ServoHB' => 'ignore',      // nom MSP : ignoré côté N3PP
        ]);

        $this->assertSame('04:00', $this->stateOf($this->n3ppTable(), 4, 104));
        $this->assertSame('300', $this->stateOf($this->n3ppTable(), 4, 105));
    }

    public function testBatchUpdateParametersCoercesNonScalarToEmptyString(): void
    {
        $this->seed($this->mspTable(), 7, [100 => 'before']);

        $repo = $this->newMspRepo();
        $repo->batchUpdateParameters(7, ['mail' => ['array', 'value']]);

        $this->assertSame('', $this->stateOf($this->mspTable(), 7, 100));
    }
}
