<?php

declare(strict_types=1);

namespace Tests\Controller\Gallery;

use App\Controller\Gallery\GalleryViewController;
use App\Repository\GalleryControlRepository;
use App\Repository\NavPageRepository;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * API /api/gallery/{slug}/photos : limite, step, meta.
 */
class GalleryListPhotosTest extends TestCase
{
    private string $tmpDir;

    private string $envKey = 'GALLERY_MSP1_DIR';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $rel = 'var/test-galleries/list_photos_' . bin2hex(random_bytes(3));
        $this->tmpDir = $root . '/' . $rel;
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
        $_ENV[$this->envKey] = $rel;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        unset($_ENV[$this->envKey]);
    }

    public function testListPhotosAppliesStepAndLimitWithMeta(): void
    {
        for ($h = 0; $h < 10; $h++) {
            $name = sprintf('2026-06-01_%02d-00-00_photo.jpg', $h);
            file_put_contents($this->tmpDir . '/' . $name, 'fake');
        }

        $controller = $this->makeController();
        $request = $this->requestWithQuery([
            'from' => '2026-06-01T00:00:00',
            'to' => '2026-06-01T23:59:59',
            'limit' => '3',
            'step' => '2',
        ]);

        $response = $controller->listPhotos($request, new Response(), ['slug' => 'msp1']);
        $this->assertSame(200, $response->getStatusCode());

        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('photos', $payload);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertSame(10, $payload['meta']['matched']);
        $this->assertSame(2, $payload['meta']['step']);
        $this->assertSame(3, $payload['meta']['limit']);
        // step=2 → 5 échantillons, limit=3 → 3 retournés, truncated
        $this->assertSame(3, $payload['meta']['returned']);
        $this->assertTrue($payload['meta']['truncated']);
        $this->assertCount(3, $payload['photos']);
    }

    public function testListPhotosRejectsInvalidRange(): void
    {
        $controller = $this->makeController();
        $request = $this->requestWithQuery([
            'from' => '2026-06-02T00:00:00+00:00',
            'to' => '2026-06-01T00:00:00+00:00',
        ]);
        $response = $controller->listPhotos($request, new Response(), ['slug' => 'msp1']);
        $this->assertSame(400, $response->getStatusCode());
    }

    private function makeController(): GalleryViewController
    {
        $renderer = $this->createMock(TemplateRenderer::class);
        $galleryRepo = $this->createMock(GalleryControlRepository::class);
        $navRepo = $this->createMock(NavPageRepository::class);

        return new GalleryViewController($renderer, $galleryRepo, $navRepo);
    }

    /** @param array<string, string> $query */
    private function requestWithQuery(array $query): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $uri = 'http://localhost/api/gallery/msp1/photos?' . http_build_query($query);

        return $factory->createServerRequest('GET', $uri)->withQueryParams($query);
    }
}
