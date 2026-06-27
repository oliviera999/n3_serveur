<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\AbstractOutputRepository;
use App\Repository\BoardRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test de CARACTÉRISATION de getStateForFirmware (AbstractOutputRepository).
 *
 * Verrouille le contrat firmware actuel : la lecture d'état acquitte ("ack")
 * immédiatement les commandes one-shot (GPIO 108/109/110) en les repassant à 0,
 * afin d'éviter les re-déclenchements (nourrissage, reset) au cycle suivant.
 *
 * Ce comportement est un effet de bord dans le repository (dette technique connue).
 * Toute extraction future vers un service métier DOIT préserver exactement ce contrat ;
 * ce test sert de filet de sécurité. (Extraction à faire avec matériel en boucle.)
 */
final class OutputFirmwareStateTest extends TestCase
{
    private PDO $pdo;
    private AbstractOutputRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE outputs_test (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            board INTEGER,
            gpio INTEGER,
            state TEXT
        )');
        // 108 = commande one-shot active ; 18 = sortie normale active.
        $this->pdo->exec("INSERT INTO outputs_test (name, board, gpio, state) VALUES
            ('feedNow', 3, 108, '1'),
            ('pump', 3, 18, '1')");

        // Faux BoardRepository : neutralise updateLastRequest() (NOW() non supporté SQLite).
        $boardRepo = new class ($this->pdo) extends BoardRepository {
            public function updateLastRequest(string $board): bool
            {
                return true;
            }
        };

        $this->repo = new class ($this->pdo, $boardRepo) extends AbstractOutputRepository {
            protected function getTable(): string
            {
                return 'outputs_test';
            }

            protected function getStateKeyColumn(): string
            {
                return 'gpio';
            }

            protected function getParamGpioMap(): array
            {
                return [];
            }

            // Ce double caractérise le mécanisme one-shot générique sur GPIO 108.
            // Le défaut de la base exclut 108/109 (server-only MSP/N3PP : notifMode /
            // notifCategories, depuis v5.9.1) ; on neutralise ce filtre ici pour
            // tester l'acquittement one-shot lui-même, sans la politique de notifs.
            protected function getServerOnlyGpios(): array
            {
                return [];
            }
        };
    }

    public function testFirstReadReturnsActiveOneShotState(): void
    {
        $state = $this->repo->getStateForFirmware(3);
        $this->assertSame('1', $state['108'], 'Le firmware doit recevoir la commande one-shot active une fois.');
        $this->assertSame('1', $state['18']);
    }

    public function testOneShotIsAcknowledgedAfterRead(): void
    {
        $this->repo->getStateForFirmware(3);

        // En BDD, la commande one-shot est repassée à 0 (ack), pas la sortie normale.
        $row108 = $this->pdo->query('SELECT state FROM outputs_test WHERE gpio = 108')->fetchColumn();
        $row18 = $this->pdo->query('SELECT state FROM outputs_test WHERE gpio = 18')->fetchColumn();
        $this->assertSame('0', (string) $row108);
        $this->assertSame('1', (string) $row18);

        // Une seconde lecture ne renvoie plus la commande active.
        $second = $this->repo->getStateForFirmware(3);
        $this->assertSame('0', $second['108']);
        $this->assertSame('1', $second['18']);
    }
}
