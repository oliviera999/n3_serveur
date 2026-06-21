<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\BoardRepository;
use App\Repository\GalleryControlRepository;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de GalleryControlRepository sur SQLite en memoire.
 *
 * Couvre :
 *  - la whitelist stricte des modules (getModule) ;
 *  - la lecture de l'etat firmware et des parametres (gpio -> state) ;
 *  - les valeurs par defaut des parametres absents ;
 *  - la mise a jour par gpio / par nom de parametre (whitelist PARAM_GPIO_MAP) ;
 *  - la version firmware (gpio 100).
 *
 * Pieges connus :
 *  - updateByGpio() utilise NOW() (MySQL), absent de SQLite : on l'override via un
 *    double de test pour eviter requestTime = NOW().
 *  - getStateForFirmware() appelle BoardRepository::updateLastRequest() qui utilise
 *    aussi NOW() : on neutralise ce double.
 */
class GalleryControlRepositoryTest extends TestCase
{
    private PDO $pdo;
    private GalleryControlRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Table historique du module FFP3 (board 5).
        $this->pdo->exec('CREATE TABLE UploadPhoto1Outputs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT, board INTEGER, gpio INTEGER, state TEXT, requestTime TEXT
        )');

        // Double : neutralise updateLastRequest() (NOW() indisponible).
        $boardRepo = new class ($this->pdo) extends BoardRepository {
            public function updateLastRequest(string $board): bool
            {
                return true;
            }
        };

        // Double : remplace NOW() par une constante pour requestTime.
        $this->repo = new class ($this->pdo, $boardRepo) extends GalleryControlRepository {
            public function updateByGpio(string $slug, int $gpio, string $state): void
            {
                $module = $this->getModule($slug);
                $table = $module['table'];
                $board = $module['board'];

                $sql = "UPDATE `{$table}` SET state = :state, requestTime = '2026-06-21 00:00:00'"
                    . ' WHERE board = :board AND gpio = :gpio';
                $this->pdo->prepare($sql)->execute([
                    ':state' => $state,
                    ':board' => $board,
                    ':gpio' => $gpio,
                ]);
            }
        };
    }

    private function insertOutput(string $name, int $board, int $gpio, string $state): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO UploadPhoto1Outputs (name, board, gpio, state) VALUES (:n, :b, :g, :s)'
        );
        $stmt->execute([':n' => $name, ':b' => $board, ':g' => $gpio, ':s' => $state]);
    }

    public function testGetModuleReturnsKnownSlug(): void
    {
        $module = $this->repo->getModule('ffp3');
        $this->assertSame(5, $module['board']);
        $this->assertSame('UploadPhoto1Outputs', $module['table']);
        $this->assertSame('FFP3', $module['label']);
    }

    public function testGetModuleRejectsUnknownSlug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->getModule('inconnu');
    }

    public function testGetStateForFirmwareMapsGpioToState(): void
    {
        $this->insertOutput('cam', 5, 12, '1');
        $this->insertOutput('flash', 5, 13, '0');
        $this->insertOutput('autreBoard', 7, 14, '1');

        $state = $this->repo->getStateForFirmware('ffp3');

        $this->assertEquals(['12' => '1', '13' => '0'], $state);
    }

    public function testGetOutputsForControlPageOrdersById(): void
    {
        $this->insertOutput('b', 5, 13, '0');
        $this->insertOutput('a', 5, 12, '1');

        $rows = $this->repo->getOutputsForControlPage('ffp3');

        $this->assertCount(2, $rows);
        $this->assertSame('b', $rows[0]['name']);
        $this->assertSame('a', $rows[1]['name']);
    }

    public function testGetParametersForControlPageUsesDefaults(): void
    {
        // Aucun parametre en base : tout retombe sur les valeurs par defaut.
        $params = $this->repo->getParametersForControlPage('ffp3');

        $this->assertSame('', $params['mail']);
        $this->assertSame('false', $params['mailNotif']);
        $this->assertSame('0', $params['forceWakeUp']);
        $this->assertSame('600', $params['sleepTime']);
        $this->assertSame('0', $params['resetMode']);
    }

    public function testGetParametersForControlPageReadsStoredValues(): void
    {
        // 102 => mail, 105 => sleepTime (cf. PARAM_GPIO_MAP).
        $this->insertOutput('p_mail', 5, 102, 'contact@x.y');
        $this->insertOutput('p_sleep', 5, 105, '1200');

        $params = $this->repo->getParametersForControlPage('ffp3');

        $this->assertSame('contact@x.y', $params['mail']);
        $this->assertSame('1200', $params['sleepTime']);
    }

    public function testGetFirmwareVersionReadsGpio100(): void
    {
        $this->insertOutput('fw', 5, 100, ' v3.2 ');
        $this->assertSame('v3.2', $this->repo->getFirmwareVersion('ffp3'));
    }

    public function testGetFirmwareVersionReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->repo->getFirmwareVersion('ffp3'));
    }

    public function testGetFirmwareVersionReturnsNullWhenBlank(): void
    {
        $this->insertOutput('fw', 5, 100, '   ');
        $this->assertNull($this->repo->getFirmwareVersion('ffp3'));
    }

    public function testUpdateByGpioChangesState(): void
    {
        $this->insertOutput('cam', 5, 12, '0');

        $this->repo->updateByGpio('ffp3', 12, '1');

        $state = $this->repo->getStateForFirmware('ffp3');
        $this->assertEquals(['12' => '1'], $state);
    }

    public function testUpdateParameterByNameUsesWhitelist(): void
    {
        $this->insertOutput('p_mail', 5, 102, '0');

        $this->assertTrue($this->repo->updateParameterByName('ffp3', 'mail', '1'));
        $this->assertFalse($this->repo->updateParameterByName('ffp3', 'parametreInconnu', '1'));

        $params = $this->repo->getParametersForControlPage('ffp3');
        $this->assertSame('1', $params['mail']);
    }

    public function testUpdateFirmwareVersionWritesGpio100(): void
    {
        $this->insertOutput('fw', 5, 100, 'v1');

        $this->repo->updateFirmwareVersion('ffp3', 'v2');

        $this->assertSame('v2', $this->repo->getFirmwareVersion('ffp3'));
    }
}
