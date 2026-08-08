<?php

namespace Tests\Service;

use App\Config\TableConfig;
use App\Service\PumpService;
use PDO;
use PHPUnit\Framework\TestCase;

class PumpServiceTest extends TestCase
{
    private PDO $pdo;
    private PumpService $service;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        TableConfig::setEnvironment('prod');

        // Configure GPIO via env
        putenv('GPIO_POMPE_AQUA=1');
        putenv('GPIO_POMPE_TANK=2');
        putenv('GPIO_RESET_MODE=3');
        putenv('LOG_FILE_PATH=php://memory');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ffp3Outputs (gpio INTEGER PRIMARY KEY, state INTEGER)');
        $this->pdo->exec('INSERT INTO ffp3Outputs (gpio, state) VALUES (1, 0), (2, 1), (3, 0)');

        $this->service = new PumpService($this->pdo);
    }

    public function testRunAndStopAquaPump(): void
    {
        // Start pump
        $this->service->runPompeAqua();
        $this->assertSame(1, $this->service->getAquaPumpState());

        // Stop pump
        $this->service->stopPompeAqua();
        $this->assertSame(0, $this->service->getAquaPumpState());
    }

    /**
     * Contrat unique du GPIO 18 depuis la 6.30.0 : 1 = ON, 0 = OFF — comme la page de
     * contrôle, le GET /api/outputs/state et le firmware. L'ancienne logique actif-bas
     * était inverse du canal lu par l'ESP32 (un « arrêt » y commandait un démarrage).
     */
    public function testRunAndStopTankPumpUseOnIsOneConvention(): void
    {
        $this->service->runPompeTank();
        $this->assertSame(1, $this->service->getTankPumpState());

        $this->service->stopPompeTank();
        $this->assertSame(0, $this->service->getTankPumpState());
    }

    public function testRebootEspSetsResetMode(): void
    {
        $this->service->rebootEsp();
        $this->assertSame(1, $this->service->getResetModeState());
    }

    // ------------------------------------------------------------------
    // Schéma complet (comme en production) : traçabilité de la commande.
    // ------------------------------------------------------------------

    /**
     * Table proche du schéma réel : colonnes `name`, `requestTime`, `lastModifiedBy`.
     */
    private function serviceWithFullSchema(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ffp3Outputs (
            gpio INTEGER PRIMARY KEY,
            name TEXT,
            state INTEGER,
            requestTime TEXT,
            lastModifiedBy TEXT
        )');
        $pdo->exec("INSERT INTO ffp3Outputs (gpio, name, state, requestTime, lastModifiedBy)
                    VALUES (1, 'Pompe aquarium', 1, '2020-01-01 00:00:00', 'esp32'),
                           (2, 'Pompe reserve', 1, '2020-01-01 00:00:00', 'esp32'),
                           (9, '', 0, '2020-01-01 00:00:00', 'esp32')");

        return [$pdo, new PumpService($pdo)];
    }

    /**
     * RÉGRESSION 6.31.0 : l'arrêt de pompe du CRON (sécurité marée) doit horodater et
     * signer la commande. Sans `requestTime`/`lastModifiedBy`, la clause anti-écrasement
     * d'OutputRepository::batchUpdateStatesSingleQuery() ne s'appliquait pas et le POST
     * firmware suivant remettait la pompe à l'état renvoyé par l'ESP32 — la sécurité
     * était annulée avant que l'appareil ait relu /api/outputs/state.
     */
    public function testStopPompeAquaStampsRequestTimeAndSource(): void
    {
        [$pdo, $service] = $this->serviceWithFullSchema();

        $service->stopPompeAqua();

        $row = $pdo->query('SELECT state, requestTime, lastModifiedBy FROM ffp3Outputs WHERE gpio = 1')
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(0, (int) $row['state']);
        $this->assertSame(PumpService::MODIFIED_BY, $row['lastModifiedBy']);
        $this->assertNotSame('2020-01-01 00:00:00', $row['requestTime'], 'requestTime doit être rafraîchi');
    }

    /**
     * La source écrite doit appartenir à la liste protégée d'OutputRepository, sinon la
     * fenêtre de priorité ne couvre pas la commande (le bug d'origine).
     */
    public function testModifiedBySourceIsProtectedFromFirmwareOverwrite(): void
    {
        $this->assertContains(
            PumpService::MODIFIED_BY,
            \App\Repository\OutputRepository::SERVER_OWNED_SOURCES
        );
    }

    /**
     * La garde « v11.38 » (ne pas toucher aux lignes sans nom) doit s'appliquer dès que
     * la colonne `name` existe. Elle était inerte en MySQL avant 6.31.0 : la détection
     * passait par `PRAGMA table_info`, syntaxe SQLite qui levait une exception avalée.
     */
    public function testNamelessRowIsNotUpdatedWhenNameColumnExists(): void
    {
        [$pdo, $service] = $this->serviceWithFullSchema();

        $service->setState(9, 1);

        $this->assertSame(
            0,
            (int) $pdo->query('SELECT state FROM ffp3Outputs WHERE gpio = 9')->fetchColumn()
        );
    }

    /**
     * Rétro-compatibilité : sur un schéma minimal (sans les colonnes de traçabilité),
     * l'UPDATE doit rester valide et ne porter que sur `state`.
     */
    public function testMinimalSchemaStillUpdatesState(): void
    {
        $this->service->setState(1, 1);
        $this->assertSame(1, $this->service->getAquaPumpState());
    }

    /**
     * Forçage OFF (GPIO 117 = 2) : le redémarrage CRON / runPompeAqua ne doit pas
     * rallumer la pompe contre la consigne opérateur.
     */
    public function testRunPompeAquaRespectsForceOff(): void
    {
        [$pdo, $service] = $this->serviceWithFullSchema();
        $pdo->exec("INSERT INTO ffp3Outputs (gpio, name, state) VALUES (117, 'Forcage pompe', 2)");
        $pdo->exec('UPDATE ffp3Outputs SET state = 0 WHERE gpio = 1');

        $service->runPompeAqua();

        $this->assertSame(0, (int) $pdo->query('SELECT state FROM ffp3Outputs WHERE gpio = 1')->fetchColumn());
    }

    /**
     * Forçage ON (GPIO 117 = 1) : stopPompeAqua (sécurité marée) ne doit pas éteindre.
     */
    public function testStopPompeAquaRespectsForceOn(): void
    {
        [$pdo, $service] = $this->serviceWithFullSchema();
        $pdo->exec("INSERT INTO ffp3Outputs (gpio, name, state) VALUES (117, 'Forcage pompe', 1)");
        $pdo->exec('UPDATE ffp3Outputs SET state = 1 WHERE gpio = 1');

        $service->stopPompeAqua();

        $this->assertSame(1, (int) $pdo->query('SELECT state FROM ffp3Outputs WHERE gpio = 1')->fetchColumn());
    }
}
