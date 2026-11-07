<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\OtaReportController;
use App\Middleware\RawPostBodyMiddleware;
use App\Repository\DeviceContactRepository;
use App\Repository\DeviceEventRepository;
use App\Security\SignatureValidator;
use App\Service\ContactRecorder;
use App\Service\LogService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `/ota-report` : HMAC obligatoire (pas de repli clé API), événements rangés dans device_events
 * avec acquittement des séquences, doublon acquitté sans double insertion, heure serveur renvoyée.
 */
final class OtaReportControllerTest extends TestCase
{
    private PDO $pdo;
    private OtaReportController $controller;

    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');
        putenv('ENV=prod');
        $_ENV['ENV'] = 'prod';
        $_ENV['API_SIG_SECRET'] = 'secret-de-test';
        $_ENV['API_KEY'] = 'cle-api';
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->controller = new OtaReportController(
            $this->createMock(LogService::class),
            new DeviceEventRepository($this->pdo),
            new ContactRecorder(new DeviceContactRepository($this->pdo), $this->createMock(LogService::class))
        );
    }

    protected function tearDown(): void
    {
        unset($_ENV['API_SIG_SECRET'], $_ENV['API_KEY']);
    }

    private function post(string $body, array $headers = [], string $family = 'ffp3'): Response
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/ffp3/ota-report', ['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $body);
        $request->getBody()->write($body);
        $request->getBody()->rewind();
        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }

        return $this->controller->handle($request, new Response(), ['family' => $family]);
    }

    /** @return array<string, string> */
    private function signed(string $body, ?int $ts = null): array
    {
        $ts ??= time();
        $nonce = 'abcdef0123456789';

        return [
            'X-Sig-Timestamp' => (string) $ts,
            'X-Sig-Nonce' => $nonce,
            'X-Sig-Hmac' => SignatureValidator::createSignatureForBody($ts, $nonce, $body, 'secret-de-test'),
        ];
    }

    private static function body(array $events, string $extra = ''): string
    {
        return 'board=esp32-wroom-a1b2c3&version=15.34&uptime=42&events=' . rawurlencode(json_encode($events)) . $extra;
    }

    public function testUnsignedReportIsRefusedEvenWithApiKey(): void
    {
        $r = $this->post(self::body([['q' => 1, 'e' => 'check']]) . '&api_key=cle-api');

        self::assertSame(401, $r->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM device_contacts WHERE accepted = 1')->fetchColumn());
        $contact = $this->pdo->query('SELECT * FROM device_contacts')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ota_report', $contact['kind']);
        self::assertSame('signature_missing', $contact['reject_reason']);
        self::assertNotSame('', $r->getHeaderLine('X-Server-Ts'), 'même un rejet renvoie l\'heure serveur');
    }

    public function testStaleSignatureIsRefusedAndClockOffsetRecorded(): void
    {
        $body = self::body([['q' => 1, 'e' => 'check']]);
        $r = $this->post($body, $this->signed($body, time() + 2045));

        self::assertSame(401, $r->getStatusCode());
        $contact = $this->pdo->query('SELECT * FROM device_contacts')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('signature_invalid', $contact['reject_reason']);
        self::assertEqualsWithDelta(2045, (int) $contact['clock_offset_s'], 3);
    }

    public function testSignedEventsAreStoredAndAcked(): void
    {
        $events = [
            ['q' => 7, 't' => 1793981517, 'u' => 30, 'e' => 'check', 'd' => 'remote=15.33 local=15.31'],
            ['q' => 8, 't' => 1793981520, 'u' => 33, 'e' => 'start', 'd' => '15.31->15.33 size=1575872'],
            ['q' => 9, 't' => 0, 'u' => 90, 'e' => 'progress', 'd' => '25%'],
            ['q' => 10, 'e' => 'fail', 'd' => 'md5 mismatch'],
            ['e' => 'rollback'],
        ];
        $body = self::body($events);
        $r = $this->post($body, $this->signed($body));

        self::assertSame(200, $r->getStatusCode());
        $json = json_decode((string) $r->getBody(), true);
        self::assertSame('ok', $json['status']);
        self::assertSame([7, 8, 9, 10], $json['acked']);
        self::assertSame(5, $json['stored']);
        self::assertEqualsWithDelta(time(), $json['server_ts'], 3);
        self::assertSame((string) $json['server_ts'], $r->getHeaderLine('X-Server-Ts'));
        self::assertStringEndsWith('GMT', $r->getHeaderLine('Date'));

        $rows = $this->pdo->query('SELECT * FROM device_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(5, $rows);
        self::assertSame('ota_report', $rows[0]['source']);
        self::assertSame('esp32-wroom-a1b2c3', $rows[0]['board']);
        self::assertSame('15.34', $rows[0]['firmware_version']);
        self::assertSame('prod', $rows[0]['env']);
        self::assertSame(1793981517, (int) $rows[0]['device_ts']);
        self::assertSame('remote=15.33 local=15.31', $rows[0]['detail']);
        self::assertSame(42, (int) $rows[4]['uptime_s'], 'uptime du rapport quand l\'événement n\'en porte pas');

        $contact = $this->pdo->query('SELECT * FROM device_contacts')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $contact['accepted']);
        self::assertSame('x_sig_body', $contact['auth_mode']);
    }

    public function testDuplicateSequenceIsAckedWithoutSecondInsert(): void
    {
        $body = self::body([['q' => 3, 'e' => 'done', 'd' => '15.33']]);
        $this->post($body, $this->signed($body));
        $r = $this->post($body, $this->signed($body));

        self::assertSame(200, $r->getStatusCode());
        self::assertSame([3], json_decode((string) $r->getBody(), true)['acked']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM device_events')->fetchColumn());
    }

    public function testLegacyBodySignatureIsAcceptedForN3pp(): void
    {
        $ts = time();
        $body = self::body([['q' => 1, 'e' => 'marked_valid']], '&timestamp=' . $ts . '&signature=' . SignatureValidator::createSignature($ts, 'secret-de-test'));
        $r = $this->post($body, [], 'n3pp');

        self::assertSame(200, $r->getStatusCode());
        $row = $this->pdo->query('SELECT family, source FROM device_events')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['family' => 'n3pp', 'source' => 'ota_report'], $row);
    }

    public function testUnknownFamilyIs404(): void
    {
        self::assertSame(404, $this->post('events=[]', [], 'pgl')->getStatusCode());
    }
}
