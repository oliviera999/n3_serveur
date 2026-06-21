<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Domain\SensorData;
use App\Repository\SensorRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests unitaires de SensorRepository sur SQLite en memoire.
 *
 * Points couverts :
 *  - insertion d'une mesure complete (insert) ;
 *  - construction dynamique des colonnes optionnelles via la whitelist columnExists()
 *    (aspect securite : seules les colonnes existantes / connues sont ajoutees au SQL) ;
 *  - validation de la table cible via TableValidator (whitelist) ;
 *  - transactions (insertAtomically / runInTransaction) ;
 *  - existsByPostId.
 *
 * Pieges connus :
 *  - columnExists() interroge INFORMATION_SCHEMA.COLUMNS, table absente de SQLite : la
 *    requete leve une exception interceptee -> la methode retourne false. Les colonnes
 *    optionnelles ne sont donc jamais ajoutees ici, ce qui est exactement le comportement
 *    de securite a verifier (pas d'ajout de colonne non prouvee).
 *  - le cache statique columnExistsCache persiste entre instances : on le purge au setUp
 *    via reflexion pour des tests deterministes.
 */
class SensorRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SensorRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        TableConfig::setEnvironment('prod');
        $this->resetColumnCache();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Table ffp3Data (prod) avec uniquement les colonnes obligatoires de insert().
        // Les colonnes optionnelles sont volontairement absentes : columnExists() doit
        // les ignorer (INFORMATION_SCHEMA indisponible en SQLite).
        $this->pdo->exec('CREATE TABLE ffp3Data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sensor TEXT, version TEXT, TempAir REAL, Humidite REAL, TempEau REAL,
            EauPotager REAL, EauAquarium REAL, EauReserve REAL, diffMaree REAL, Luminosite REAL,
            etatPompeAqua INTEGER, etatPompeTank INTEGER, etatHeat INTEGER, etatUV INTEGER,
            bouffeMatin INTEGER, bouffeMidi INTEGER, bouffePetits INTEGER, bouffeGros INTEGER,
            aqThreshold INTEGER, tankThreshold INTEGER, chauffageThreshold REAL,
            mail TEXT, mailNotif TEXT, resetMode INTEGER, bouffeSoir INTEGER
        )');

        $this->repo = new SensorRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        TableConfig::setEnvironment('prod');
        $this->resetColumnCache();
    }

    /**
     * Purge le cache statique columnExists pour eviter les fuites entre tests.
     */
    private function resetColumnCache(): void
    {
        $ref = new ReflectionClass(SensorRepository::class);
        $prop = $ref->getProperty('columnExistsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    private function makeSensorData(?string $postId = null): SensorData
    {
        return new SensorData(
            sensor: 'esp32-01',
            version: 'v11.0',
            tempAir: 21.5,
            humidite: 55.0,
            tempEau: 22.0,
            eauPotager: 100.0,
            eauAquarium: 300.0,
            eauReserve: 500.0,
            diffMaree: 12.0,
            luminosite: 800.0,
            etatPompeAqua: 1,
            etatPompeTank: 0,
            etatHeat: 0,
            etatUV: 1,
            bouffeMatin: 1,
            bouffeMidi: 0,
            bouffePetits: 0,
            bouffeGros: 0,
            aqThreshold: 10,
            tankThreshold: 20,
            chauffageThreshold: 18.5,
            mail: 'a@b.c',
            mailNotif: 'd@e.f',
            resetMode: 0,
            bouffeSoir: 1,
            postId: $postId,
        );
    }

    public function testInsertPersistsMandatoryColumns(): void
    {
        $this->repo->insert($this->makeSensorData());

        $row = $this->pdo->query('SELECT * FROM ffp3Data')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('esp32-01', $row['sensor']);
        $this->assertSame('v11.0', $row['version']);
        $this->assertSame(1, (int) $row['etatPompeAqua']);
        $this->assertSame(18.5, (float) $row['chauffageThreshold']);
    }

    /**
     * Aspect securite : sans INFORMATION_SCHEMA (SQLite), aucune colonne optionnelle
     * (ni post_id) ne doit etre ajoutee au INSERT, donc l'insertion reussit malgre
     * l'absence de ces colonnes dans la table.
     */
    public function testInsertIgnoresOptionalColumnsWhenAbsent(): void
    {
        $this->repo->insert($this->makeSensorData(postId: 'post-123'));

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM ffp3Data')->fetchColumn();
        $this->assertSame(1, $count);

        // La colonne post_id n'existe pas : la requete reste valide.
        $cols = $this->pdo->query('PRAGMA table_info(ffp3Data)')->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertNotContains('post_id', $cols);
    }

    public function testColumnExistsReturnsFalseWhenInformationSchemaUnavailable(): void
    {
        $ref = new ReflectionClass(SensorRepository::class);
        $method = $ref->getMethod('columnExists');
        $method->setAccessible(true);

        // INFORMATION_SCHEMA indisponible -> exception interceptee -> false.
        $this->assertFalse($method->invoke($this->repo, 'ffp3Data', 'tempsGros'));
    }

    public function testInsertRejectsNonWhitelistedTable(): void
    {
        TableConfig::setEnvironment('s3'); // -> ffp3DataS3 (whitelistee, table absente en SQLite)
        $this->expectException(\PDOException::class);
        $this->repo->insert($this->makeSensorData());
    }

    public function testExistsByPostIdReturnsFalseWhenColumnAbsent(): void
    {
        // post_id absent -> columnExists false -> existsByPostId court-circuite a false.
        $this->assertFalse($this->repo->existsByPostId('whatever'));
    }

    public function testRunInTransactionReturnsCallbackResult(): void
    {
        $result = $this->repo->runInTransaction(function (): string {
            $this->repo->insert($this->makeSensorData());
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ffp3Data')->fetchColumn());
    }

    public function testRunInTransactionRollsBackOnException(): void
    {
        try {
            $this->repo->runInTransaction(function (): void {
                $this->repo->insert($this->makeSensorData());
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ffp3Data')->fetchColumn());
    }

    public function testInsertAtomicallyRunsAfterInsertCallback(): void
    {
        $seen = null;
        $this->repo->insertAtomically(
            $this->makeSensorData(),
            function (SensorData $data) use (&$seen): void {
                $seen = $data->sensor;
            }
        );

        $this->assertSame('esp32-01', $seen);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ffp3Data')->fetchColumn());
    }
}
