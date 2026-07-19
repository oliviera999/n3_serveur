<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Controller\Ffp3\OtaFileController;
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
}
