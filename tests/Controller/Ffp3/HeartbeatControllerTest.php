<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Controller\Ffp3\HeartbeatController;
use App\Middleware\RawPostBodyMiddleware;
use App\Repository\HeartbeatRepository;
use App\Security\SignatureValidator;
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
            null, // hmacAuditLogger (?HmacAuditLogger, final class -> non mockable, usage null-safe)
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

    // --- Authentification HMAC OPTIONNELLE (en-têtes X-Sig-*) ---

    private const SIG_SECRET = 'unit-test-heartbeat-sig-secret';

    /**
     * Requête heartbeat avec en-têtes X-Sig-* signant le corps brut (comme le firmware).
     * Le corps brut est aussi exposé via l'attribut RawPostBodyMiddleware (capté en prod).
     */
    private function signedPostRequest(array $params, string $rawBody, ?string $signature = null, ?string $nonce = null)
    {
        $timestamp = (string) time();
        $nonce ??= 'a1b2c3d4e5f60718';
        $signature ??= SignatureValidator::createSignatureForBody((int) $timestamp, $nonce, $rawBody, self::SIG_SECRET);

        return $this->postRequest($params)
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $rawBody)
            ->withHeader('X-Sig-Timestamp', $timestamp)
            ->withHeader('X-Sig-Nonce', $nonce)
            ->withHeader('X-Sig-Hmac', $signature);
    }

    /** En-têtes X-Sig valides -> 200 + insertion (et CRC toujours validé). */
    public function testValidHmacHeadersAccepted(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;

        $crc = self::crcFor(123456, 80000, 60000, 3);
        $params = ['uptime' => '123456', 'free' => '80000', 'min' => '60000', 'reboots' => '3', 'crc' => $crc];
        $rawBody = "uptime=123456&free=80000&min=60000&reboots=3&crc={$crc}&ip=192.168.1.42";

        $response = $this->makeController()->handle($this->signedPostRequest($params, $rawBody), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->rowCount());
    }

    /** Signature X-Sig invalide -> 401, aucune insertion (l'auth prime sur le CRC valide). */
    public function testInvalidHmacSignatureRejected(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;

        $crc = self::crcFor(123456, 80000, 60000, 3);
        $params = ['uptime' => '123456', 'free' => '80000', 'min' => '60000', 'reboots' => '3', 'crc' => $crc];
        $rawBody = "uptime=123456&free=80000&min=60000&reboots=3&crc={$crc}&ip=192.168.1.42";

        $request = $this->signedPostRequest($params, $rawBody, signature: str_repeat('0', 64));
        $response = $this->makeController()->handle($request, new Response());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(0, $this->rowCount());
    }

    /** En-têtes X-Sig incomplets (timestamp + hmac sans nonce) -> 401. */
    public function testIncompleteHmacHeadersRejected(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;

        $crc = self::crcFor(123456, 80000, 60000, 3);
        $params = ['uptime' => '123456', 'free' => '80000', 'min' => '60000', 'reboots' => '3', 'crc' => $crc];

        $request = $this->postRequest($params)
            ->withHeader('X-Sig-Timestamp', (string) time())
            ->withHeader('X-Sig-Hmac', str_repeat('a', 64));
        // pas de X-Sig-Nonce

        $response = $this->makeController()->handle($request, new Response());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(0, $this->rowCount());
    }
}
