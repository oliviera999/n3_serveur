<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Controller\Ffp3\HeartbeatController;
use App\Repository\HeartbeatRepository;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Caractérisation du HeartbeatController (chemin firmware, sans couverture jusqu'ici).
 *
 * Le firmware (ffp5cs WebClient::buildHeartbeatPayload) envoie
 *   uptime=<u>&free=<f>&min=<m>&reboots=<r>&crc=<CRC32B(uptime=..&free=..&min=..&reboots=..)>&ip=<ip>
 * Le serveur valide le CRC32 (intégrité, PAS authentification) puis insère.
 *
 * NB sécurité : aucune vérification API_KEY/HMAC sur ce chemin (le CRC32 n'est pas secret).
 * Ces tests verrouillent le comportement ACTUEL ; durcir l'auth est une décision contrat
 * séparée (cf. CONTRAT_FIRMWARE_SERVEUR.md), volontairement non appliquée ici.
 */
class HeartbeatControllerTest extends TestCase
{
    private PDO $pdo;
    private HeartbeatRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        // ENV=prod -> TableConfig::getHeartbeatTable() = 'ffp3Heartbeat'
        putenv('ENV=prod');
        $_ENV['ENV'] = 'prod';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ffp3Heartbeat (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uptime INTEGER, freeHeap INTEGER, minHeap INTEGER, reboots INTEGER
        )');
        $this->repo = new HeartbeatRepository($this->pdo);
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ffp3Heartbeat')->fetchColumn();
    }

    /** Construit le CRC comme le firmware (CRC32B hex majuscule). */
    private static function crcFor(int $uptime, int $free, int $min, int $reboots): string
    {
        return strtoupper(hash('crc32b', "uptime={$uptime}&free={$free}&min={$min}&reboots={$reboots}"));
    }

    private function makeController(): HeartbeatController
    {
        return new HeartbeatController(
            $this->createMock(LogService::class),
            $this->createMock(ErrorAlertService::class),
            $this->repo,
        );
    }

    private function postRequest(array $params)
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/heartbeat')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($params);
    }

    /** CRC valide -> 200 OK et une ligne insérée. */
    public function testValidHeartbeatInsertsAndReturns200(): void
    {
        $params = [
            'uptime' => '123456',
            'free' => '80000',
            'min' => '60000',
            'reboots' => '3',
            'crc' => self::crcFor(123456, 80000, 60000, 3),
        ];

        $response = $this->makeController()->handle($this->postRequest($params), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('OK', (string) $response->getBody());
        $this->assertSame(1, $this->rowCount());

        $row = $this->pdo->query('SELECT uptime, freeHeap, minHeap, reboots FROM ffp3Heartbeat')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(123456, (int) $row['uptime']);
        $this->assertSame(80000, (int) $row['freeHeap']);
        $this->assertSame(60000, (int) $row['minHeap']);
        $this->assertSame(3, (int) $row['reboots']);
    }

    /** CRC invalide -> 400 et aucune insertion. */
    public function testBadCrcReturns400AndDoesNotInsert(): void
    {
        $params = [
            'uptime' => '123456',
            'free' => '80000',
            'min' => '60000',
            'reboots' => '3',
            'crc' => 'DEADBEEF',
        ];

        $response = $this->makeController()->handle($this->postRequest($params), new Response());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->rowCount());
    }

    /** Champ requis manquant -> 400 avant tout calcul CRC. */
    public function testMissingFieldReturns400(): void
    {
        $params = [
            'uptime' => '123456',
            'free' => '80000',
            'min' => '60000',
            // reboots manquant
            'crc' => 'AABBCCDD',
        ];

        $response = $this->makeController()->handle($this->postRequest($params), new Response());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->rowCount());
    }

    /** Méthode non-POST -> 405. */
    public function testNonPostReturns405(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/heartbeat');

        $response = $this->makeController()->handle($request, new Response());

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame(0, $this->rowCount());
    }
}
