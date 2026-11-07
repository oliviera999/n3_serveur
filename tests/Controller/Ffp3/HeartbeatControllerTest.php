<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Controller\Ffp3\HeartbeatController;
use App\Middleware\RawPostBodyMiddleware;
use App\Repository\DeviceContactRepository;
use App\Repository\DeviceEventRepository;
use App\Repository\HeartbeatRepository;
use App\Security\SignatureValidator;
use App\Service\ContactRecorder;
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
    // ── 6.59.0 : heartbeat enrichi, journal des contacts, heure serveur ────────────────

    private function diagnosticController(): HeartbeatController
    {
        HeartbeatRepository::resetColumnCache();
        return new HeartbeatController(
            $this->createMock(LogService::class),
            null,
            $this->createMock(ErrorAlertService::class),
            $this->repo,
            null,
            new ContactRecorder(new DeviceContactRepository($this->pdo), $this->createMock(LogService::class)),
            new DeviceEventRepository($this->pdo)
        );
    }

    /** Un firmware 15.34 envoie les champs enrichis : stockés quand les colonnes existent, journal → device_events. */
    public function testEnrichedHeartbeatStoresDiagnosticColumnsAndBootEvent(): void
    {
        HeartbeatRepository::resetColumnCache();
        $this->pdo->exec('ALTER TABLE ffp3Heartbeat ADD COLUMN resetReason TEXT');
        $this->pdo->exec('ALTER TABLE ffp3Heartbeat ADD COLUMN otaState TEXT');
        $this->pdo->exec('ALTER TABLE ffp3Heartbeat ADD COLUMN safeMode INTEGER');
        $this->pdo->exec('ALTER TABLE ffp3Heartbeat ADD COLUMN clockOffsetS INTEGER');
        $this->pdo->exec('ALTER TABLE ffp3Heartbeat ADD COLUMN version TEXT');
        $this->pdo->exec('ALTER TABLE ffp3Heartbeat ADD COLUMN maxBlock INTEGER');
        $this->pdo->exec('ALTER TABLE ffp3Heartbeat ADD COLUMN idlePct INTEGER');
        // partition / failedBoots / rssi / wifi* / nvsUsedPct absentes : doivent être ignorées sans erreur.

        $params = [
            'uptime' => '31', 'free' => '80000', 'min' => '60000', 'reboots' => '106',
            'crc' => self::crcFor(31, 80000, 60000, 106),
            'version' => '15.34', 'rst' => 'SW', 'part' => 'app1', 'ota' => 'pending', 'fails' => '0', 'safe' => '0',
            'clk' => '-3', 'rssi' => '-67', 'wifi_ms' => '2100', 'wifi_tries' => '1', 'board' => 'esp32-wroom-a1b2c3', 'boot' => '1',
            'blk' => '31732', 'idle' => '87', 'nvs' => '41',
            'log' => json_encode([
                ['q' => 41, 't' => 1793981640, 'u' => 5, 'e' => 'wifi_ok', 'd' => 'rssi=-67 2100ms'],
                ['q' => 42, 't' => 1793981650, 'u' => 15, 'e' => 'ntp_ok', 'd' => 'offset=-3'],
            ]),
        ];
        $response = $this->diagnosticController()->handle($this->postRequest($params), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame('', $response->getHeaderLine('X-Server-Ts'));
        $this->assertStringEndsWith('GMT', $response->getHeaderLine('Date'));

        $row = $this->pdo->query('SELECT * FROM ffp3Heartbeat')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('SW', $row['resetReason']);
        $this->assertSame('pending', $row['otaState']);
        $this->assertSame(0, (int) $row['safeMode']);
        $this->assertSame(-3, (int) $row['clockOffsetS']);
        $this->assertSame('15.34', $row['version']);
        $this->assertSame(31732, (int) $row['maxBlock'], 'plus grand bloc libre (fragmentation)');
        $this->assertSame(87, (int) $row['idlePct']);

        $events = $this->pdo->query('SELECT source, seq, event, detail, board FROM device_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $events);
        $this->assertSame('boot', $events[0]['source']);
        $this->assertStringContainsString('rst=SW part=app1 ota=pending fails=0 safe=0 reboots=106', $events[0]['detail']);
        $this->assertSame('heartbeat_log', $events[1]['source']);
        $this->assertSame(41, (int) $events[1]['seq']);
        $this->assertSame('wifi_ok', $events[1]['event']);
        $this->assertSame('esp32-wroom-a1b2c3', $events[2]['board']);

        $contact = $this->pdo->query('SELECT * FROM device_contacts')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('heartbeat', $contact['kind']);
        $this->assertSame(1, (int) $contact['accepted']);
        $this->assertSame('crc_only', $contact['auth_mode'], 'sans X-Sig le heartbeat FFP3 n\'est PAS authentifié');
        $this->assertSame('15.34', $contact['firmware_version']);
    }

    /** Rejet HMAC : journalisé avec le motif et le décalage d'horloge (cas du 06/11/2026, +2 045 s). */
    public function testRejectedHmacHeartbeatIsJournaledWithClockOffset(): void
    {
        $_ENV['API_SIG_SECRET'] = 'secret-de-test';
        $body = 'uptime=506249&free=54724&min=25100&reboots=105&crc=' . self::crcFor(506249, 54724, 25100, 105);
        $ts = time() + 2045;
        $request = $this->postRequest(['uptime' => '506249', 'free' => '54724', 'min' => '25100', 'reboots' => '105', 'crc' => self::crcFor(506249, 54724, 25100, 105)])
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $body)
            ->withHeader('X-Sig-Timestamp', (string) $ts)
            ->withHeader('X-Sig-Nonce', 'abcdef0123456789')
            ->withHeader('X-Sig-Hmac', SignatureValidator::createSignatureForBody($ts, 'abcdef0123456789', $body, 'secret-de-test'));

        $response = $this->diagnosticController()->handle($request, new Response());
        unset($_ENV['API_SIG_SECRET']);

        $this->assertSame(401, $response->getStatusCode(), 'horodatage hors fenêtre : signature refusée');
        $this->assertSame(0, $this->rowCount());
        $this->assertNotSame('', $response->getHeaderLine('X-Server-Ts'), 'le firmware peut se recaler sur la réponse de rejet');
        $contact = $this->pdo->query('SELECT * FROM device_contacts')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $contact['accepted']);
        $this->assertSame('signature_invalid', $contact['reject_reason']);
        $this->assertEqualsWithDelta(2045, (int) $contact['clock_offset_s'], 3);
    }
}
