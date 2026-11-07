<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Controller\Ffp3\OtaFileController;
use App\Repository\OtaEventRepository;
use App\Service\LogService;
use App\Service\OperationalSettingsService;
use App\Service\OtaChannelPolicy;
use App\Service\OtaEventRecorder;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Tests du serveur de fichiers OTA + support HTTP Range (audit S3).
 *
 * Vérifie : téléchargement complet (200, rétrocompatible), Range partiel (206) sous
 * toutes ses formes, range hors limites (416), Range non analysable ignoré (200),
 * protection path traversal, auth opt-in, et types de contenu.
 */
class OtaFileControllerTest extends TestCase
{
    private string $otaDir;
    private string $content;

    protected function setUp(): void
    {
        // Auth désactivée par défaut (réinitialise un éventuel résidu d'un autre test).
        unset($_ENV['OTA_REQUIRE_AUTH'], $_ENV['API_KEY']);

        $this->otaDir = sys_get_temp_dir() . '/ota_test_' . bin2hex(random_bytes(6));
        @mkdir($this->otaDir . '/test', 0775, true);
        // 1000 octets à motif connu : permet de vérifier exactement les tranches.
        $this->content = str_repeat('ABCDEFGHIJ', 100);
        file_put_contents($this->otaDir . '/test/firmware.bin', $this->content);
        file_put_contents($this->otaDir . '/test/metadata.json', '{"version":"1.0"}');
    }

    protected function tearDown(): void
    {
        @unlink($this->otaDir . '/test/firmware.bin');
        @unlink($this->otaDir . '/test/metadata.json');
        @rmdir($this->otaDir . '/test');
        @rmdir($this->otaDir);
    }

    private function get(string $path, array $headers = [], array $query = []): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/ota/' . $path)
            ->withQueryParams($query);
        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }
        $controller = new OtaFileController($this->otaDir);
        return $controller($request, new Response(), ['path' => $path]);
    }

    public function testFullDownloadReturns200WithHeaders(): void
    {
        $r = $this->get('test/firmware.bin');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('application/octet-stream', $r->getHeaderLine('Content-Type'));
        $this->assertSame((string) strlen($this->content), $r->getHeaderLine('Content-Length'));
        $this->assertSame('bytes', $r->getHeaderLine('Accept-Ranges'));
        $this->assertSame($this->content, (string) $r->getBody());
    }

    public function testJsonContentType(): void
    {
        $r = $this->get('test/metadata.json');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('application/json', $r->getHeaderLine('Content-Type'));
    }

    public function testRangeStartEndReturns206(): void
    {
        $r = $this->get('test/firmware.bin', ['Range' => 'bytes=100-199']);

        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 100-199/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame('100', $r->getHeaderLine('Content-Length'));
        $this->assertSame(substr($this->content, 100, 100), (string) $r->getBody());
    }

    public function testRangeFromStart(): void
    {
        $r = $this->get('test/firmware.bin', ['Range' => 'bytes=0-9']);
        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 0-9/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame(substr($this->content, 0, 10), (string) $r->getBody());
    }

    public function testRangeOpenEndedToEof(): void
    {
        $r = $this->get('test/firmware.bin', ['Range' => 'bytes=990-']);
        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 990-999/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame('10', $r->getHeaderLine('Content-Length'));
        $this->assertSame(substr($this->content, 990), (string) $r->getBody());
    }

    public function testRangeSuffixLastBytes(): void
    {
        $r = $this->get('test/firmware.bin', ['Range' => 'bytes=-50']);
        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 950-999/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame(substr($this->content, 950), (string) $r->getBody());
    }

    public function testRangeEndBeyondEofClampsToFileSize(): void
    {
        $r = $this->get('test/firmware.bin', ['Range' => 'bytes=995-100000']);
        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 995-999/1000', $r->getHeaderLine('Content-Range'));
        $this->assertSame('5', $r->getHeaderLine('Content-Length'));
    }

    public function testUnsatisfiableRangeStartBeyondEofReturns416(): void
    {
        $r = $this->get('test/firmware.bin', ['Range' => 'bytes=2000-2100']);
        $this->assertSame(416, $r->getStatusCode());
        $this->assertSame('bytes */1000', $r->getHeaderLine('Content-Range'));
    }

    public function testUnsatisfiableRangeStartGreaterThanEndReturns416(): void
    {
        $r = $this->get('test/firmware.bin', ['Range' => 'bytes=500-100']);
        $this->assertSame(416, $r->getStatusCode());
    }

    /** RFC 7233 : un Range non analysable est ignoré → réponse 200 complète (pas 400/416). */
    public function testMalformedRangeIsIgnoredAndServesFull200(): void
    {
        $r = $this->get('test/firmware.bin', ['Range' => 'bytes=abc']);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame($this->content, (string) $r->getBody());
    }

    /** Multi-range (rare, inutile à l'OTA) : ignoré → 200 complet. */
    public function testMultiRangeIgnoredServesFull200(): void
    {
        $r = $this->get('test/firmware.bin', ['Range' => 'bytes=0-10,20-30']);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame(strlen($this->content), strlen((string) $r->getBody()));
    }

    public function testPathTraversalReturns400(): void
    {
        $r = $this->get('../../../etc/passwd');
        $this->assertSame(400, $r->getStatusCode());
    }

    public function testEmptyPathReturns400(): void
    {
        $r = $this->get('');
        $this->assertSame(400, $r->getStatusCode());
    }

    /**
     * Défense en profondeur (L4) : un lien symbolique dans `ota/` pointant hors
     * base est refusé par la vérification realpath (404), même sans `..` dans l'URL.
     */
    public function testSymlinkEscapeReturns404(): void
    {
        $outside = sys_get_temp_dir() . '/ota_outside_' . bin2hex(random_bytes(6));
        file_put_contents($outside, 'SECRET');
        $link = $this->otaDir . '/test/escape.bin';
        if (!@symlink($outside, $link)) {
            @unlink($outside);
            $this->markTestSkipped('symlink() indisponible sur cet environnement.');
        }

        $r = $this->get('test/escape.bin');

        @unlink($link);
        @unlink($outside);
        $this->assertSame(404, $r->getStatusCode());
    }

    public function testNonExistentFileReturns404(): void
    {
        $r = $this->get('test/missing.bin', ['Range' => 'bytes=0-10']);
        $this->assertSame(404, $r->getStatusCode());
    }

    public function testAuthRequiredRejectsWithoutKey(): void
    {
        $_ENV['OTA_REQUIRE_AUTH'] = 'true';
        $_ENV['API_KEY'] = 'secret-key';

        $r = $this->get('test/firmware.bin');
        $this->assertSame(401, $r->getStatusCode());
    }

    public function testAuthRequiredAcceptsWithHeaderKey(): void
    {
        $_ENV['OTA_REQUIRE_AUTH'] = 'true';
        $_ENV['API_KEY'] = 'secret-key';

        $r = $this->get('test/firmware.bin', ['X-Api-Key' => 'secret-key']);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame($this->content, (string) $r->getBody());
    }

    public function testAuthRequiredAcceptsWithQueryKey(): void
    {
        $_ENV['OTA_REQUIRE_AUTH'] = 'true';
        $_ENV['API_KEY'] = 'secret-key';

        $r = $this->get('test/firmware.bin', [], ['api_key' => 'secret-key']);
        $this->assertSame(200, $r->getStatusCode());
    }
    // ── Journalisation ota_events + pause / canari (6.59.0) ──────────────────────────

    /** @return array{0: OtaFileController, 1: PDO} */
    private function journaledController(?OperationalSettingsService $settings = null): array
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $controller = new OtaFileController(
            $this->otaDir,
            null,
            new OtaEventRecorder(new OtaEventRepository($pdo), $this->createMock(LogService::class)),
            new OtaChannelPolicy($settings)
        );

        return [$controller, $pdo];
    }

    private function settingsWith(array $values): OperationalSettingsService
    {
        $settings = $this->createMock(OperationalSettingsService::class);
        $settings->method('string')->willReturnCallback(static fn (string $key, string $default = ''): string => $values[$key] ?? $default);
        $settings->method('bool')->willReturnCallback(static fn (string $key, bool $default): bool => $default);

        return $settings;
    }

    /** @return list<array<string, mixed>> */
    private function events(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM ota_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testFullDownloadIsJournaledAsComplete(): void
    {
        [$controller, $pdo] = $this->journaledController();
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/ota/test/firmware.bin', ['REMOTE_ADDR' => '105.155.251.0'])
            ->withHeader('X-Ota-Board', 'esp32-wroom-a1b2c3')
            ->withHeader('X-Ota-Version', '15.31');
        $r = $controller($request, new Response(), ['path' => 'test/firmware.bin']);
        $this->assertSame(200, $r->getStatusCode());

        $events = $this->events($pdo);
        $this->assertCount(1, $events);
        $e = $events[0];
        $this->assertSame('test/firmware.bin', $e['path']);
        $this->assertSame('test', $e['channel']);
        $this->assertSame('bin', $e['kind']);
        $this->assertSame('esp32-wroom-a1b2c3', $e['board']);
        $this->assertSame('15.31', $e['firmware_version']);
        $this->assertSame('105.155.251.0', $e['ip']);
        $this->assertSame(200, (int) $e['http_status']);
        $this->assertSame(1000, (int) $e['bytes_sent']);
        $this->assertSame(1000, (int) $e['file_size']);
        $this->assertSame(1, (int) $e['complete']);
        $this->assertNotNull($e['duration_ms']);
    }

    public function testPartialRangeIsJournaledAsIncompleteUnlessItReachesTheEnd(): void
    {
        [$controller, $pdo] = $this->journaledController();
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/ota/test/firmware.bin')->withHeader('Range', 'bytes=0-99');
        $controller($request, new Response(), ['path' => 'test/firmware.bin']);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/ota/test/firmware.bin')->withHeader('Range', 'bytes=100-');
        $controller($request, new Response(), ['path' => 'test/firmware.bin']);

        $events = $this->events($pdo);
        $this->assertCount(2, $events);
        $this->assertSame('bytes=0-99', $events[0]['range_header']);
        $this->assertSame(100, (int) $events[0]['bytes_sent']);
        $this->assertSame(0, (int) $events[0]['complete']);
        $this->assertSame(900, (int) $events[1]['bytes_sent']);
        $this->assertSame(1, (int) $events[1]['complete'], 'la reprise qui atteint la fin clôt le transfert');
    }

    public function testMetadataRequestsAndNotFoundAreJournaledToo(): void
    {
        [$controller, $pdo] = $this->journaledController();
        $controller((new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/ota/test/metadata.json'), new Response(), ['path' => 'test/metadata.json']);
        $controller((new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/ota/metadata.json'), new Response(), ['path' => 'metadata.json']);

        $events = $this->events($pdo);
        $this->assertSame('metadata', $events[0]['kind']);
        $this->assertSame(200, (int) $events[0]['http_status']);
        $this->assertSame('root', $events[1]['channel'], 'manifeste racine ffp5cs = canal root');
        $this->assertSame(404, (int) $events[1]['http_status']);
        $this->assertSame('not_found', $events[1]['note']);
    }

    public function testPausedChannelIsRefusedWithoutServingBytes(): void
    {
        [$controller, $pdo] = $this->journaledController($this->settingsWith(['OTA_PAUSED_CHANNELS' => 'test, esp32-wroom']));
        $r = $controller((new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/ota/test/firmware.bin'), new Response(), ['path' => 'test/firmware.bin']);

        $this->assertSame(503, $r->getStatusCode());
        $this->assertSame('1800', $r->getHeaderLine('Retry-After'));
        $events = $this->events($pdo);
        $this->assertSame('paused', $events[0]['note']);
        $this->assertSame(0, (int) $events[0]['bytes_sent']);
    }

    public function testCanaryChannelServesOnlyListedBoards(): void
    {
        [$controller, $pdo] = $this->journaledController($this->settingsWith(['OTA_CANARY_CHANNELS' => 'test', 'OTA_CANARY_BOARDS' => 'esp32-wroom-canari']));
        $factory = new ServerRequestFactory();

        $denied = $controller($factory->createServerRequest('GET', 'http://localhost/ota/test/firmware.bin')->withHeader('X-Ota-Board', 'esp32-wroom-prod'), new Response(), ['path' => 'test/firmware.bin']);
        $anonymous = $controller($factory->createServerRequest('GET', 'http://localhost/ota/test/firmware.bin'), new Response(), ['path' => 'test/firmware.bin']);
        $allowed = $controller($factory->createServerRequest('GET', 'http://localhost/ota/test/firmware.bin')->withHeader('X-Ota-Board', 'esp32-wroom-canari'), new Response(), ['path' => 'test/firmware.bin']);

        $this->assertSame(503, $denied->getStatusCode());
        $this->assertSame(503, $anonymous->getStatusCode(), 'une carte qui ne s\'identifie pas attend la fin du canari');
        $this->assertSame(200, $allowed->getStatusCode());
        $notes = array_column($this->events($pdo), 'note');
        $this->assertSame(['canary_denied', 'canary_denied', null], $notes);
    }

    public function testChannelAndKindDerivation(): void
    {
        $this->assertSame('esp32-wroom', OtaChannelPolicy::channelOf('esp32-wroom/firmware.bin'));
        $this->assertSame('root', OtaChannelPolicy::channelOf('metadata.json'));
        $this->assertSame('cam', OtaChannelPolicy::channelOf('cam/ffp3/firmware.bin'));
        $this->assertSame('bin', OtaChannelPolicy::kindOf('esp32-wroom/firmware.bin'));
        $this->assertSame('metadata', OtaChannelPolicy::kindOf('n3pp/metadata.json'));
        $this->assertSame('other', OtaChannelPolicy::kindOf('esp32-s3/littlefs.img'));
    }
}
