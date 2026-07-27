<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Repository\OutputRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Sémantique du compteur renvoyé par `OutputRepository::updateMultipleParameters()`.
 *
 * RÉGRESSION 6.34.0 : le compteur incrémentait sur le retour de
 * `PDOStatement::execute()`, qui vaut `true` dès que la requête part sans erreur —
 * y compris quand elle ne touche AUCUNE ligne (GPIO absent de la table). L'API
 * annonçait donc des paramètres « enregistrés » qui ne l'étaient pas.
 */
final class OutputRepositoryUpdateCountTest extends TestCase
{
    private PDO $pdo;
    private OutputRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        TableConfig::setEnvironment('prod');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ffp3Outputs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            board INTEGER,
            gpio INTEGER,
            name TEXT,
            state TEXT,
            requestTime TEXT,
            lastModifiedBy TEXT
        )');

        $this->repo = new OutputRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        TableConfig::setEnvironment('prod');
    }

    private function seedGpio(int $gpio, string $state): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO ffp3Outputs (board, gpio, name, state, requestTime, lastModifiedBy)
             VALUES (1, :gpio, 'param', :state, '2020-01-01 00:00:00', 'esp32')"
        );
        $stmt->execute([':gpio' => $gpio, ':state' => $state]);
    }

    private function stateOf(int $gpio): ?string
    {
        $stmt = $this->pdo->prepare('SELECT state FROM ffp3Outputs WHERE gpio = :g');
        $stmt->execute([':g' => $gpio]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * `aqThr` → GPIO 102, `taThr` → GPIO 103 (cf. Ffp3GpioMap).
     */
    public function testCountsOnlyParametersWhoseRowExists(): void
    {
        $this->seedGpio(102, '18'); // seul aqThr a une ligne en base

        $updated = $this->repo->updateMultipleParameters(['aqThr' => 25, 'taThr' => 30]);

        $this->assertSame(1, $updated, 'taThr n\'a pas de ligne : il ne doit pas être compté');
        $this->assertSame('25', $this->stateOf(102));
        $this->assertNull($this->stateOf(103));
    }

    public function testCountsEveryParameterActuallyPersisted(): void
    {
        $this->seedGpio(102, '18');
        $this->seedGpio(103, '20');

        $updated = $this->repo->updateMultipleParameters(['aqThr' => 25, 'taThr' => 30]);

        $this->assertSame(2, $updated);
        $this->assertSame('25', $this->stateOf(102));
        $this->assertSame('30', $this->stateOf(103));
    }

    public function testUnknownParameterNameIsIgnored(): void
    {
        $this->seedGpio(102, '18');

        $updated = $this->repo->updateMultipleParameters(['aqThr' => 25, 'parametreInconnu' => 1]);

        $this->assertSame(1, $updated);
    }

    /**
     * L'écriture trace bien sa source : `requestTime` rafraîchi et
     * `lastModifiedBy = 'web'` (fenêtre de priorité face au POST firmware).
     */
    public function testUpdateStampsWebOwnership(): void
    {
        $this->seedGpio(102, '18');

        $this->repo->updateMultipleParameters(['aqThr' => 25]);

        $row = $this->pdo->query('SELECT requestTime, lastModifiedBy FROM ffp3Outputs WHERE gpio = 102')
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('web', $row['lastModifiedBy']);
        $this->assertNotSame('2020-01-01 00:00:00', $row['requestTime']);
    }
}
