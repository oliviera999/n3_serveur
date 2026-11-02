<?php

declare(strict_types=1);

namespace Tests\Controller\Gallery;

use App\Controller\Gallery\GalleryViewController;
use App\Repository\GalleryControlRepository;
use App\Repository\NavPageRepository;
use App\Service\GalleryNormalizeService;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * API /api/gallery/{slug}/photos : limite, step, échantillonnage uniforme, meta, jeton de version.
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
        // step=2 → 5 échantillons voulus, limit=3 → pas augmenté automatiquement, truncated
        $this->assertSame(3, $payload['meta']['returned']);
        $this->assertTrue($payload['meta']['truncated']);
        $this->assertCount(3, $payload['photos']);
        // La plage reste couverte en entier : première ET dernière photo présentes.
        $this->assertStringContainsString('2026-06-01_00-00-00', $payload['photos'][0]['url']);
        $this->assertStringContainsString('2026-06-01_09-00-00', $payload['photos'][2]['url']);
    }

    public function testListPhotosDownsamplesUniformlyInsteadOfCuttingTheEnd(): void
    {
        for ($h = 0; $h < 10; $h++) {
            file_put_contents($this->tmpDir . '/' . sprintf('2026-06-01_%02d-00-00_photo.jpg', $h), 'fake');
        }

        $response = $this->makeController()->listPhotos($this->requestWithQuery([
            'from' => '2026-06-01T00:00:00',
            'to' => '2026-06-01T23:59:59',
            'limit' => '4',
        ]), new Response(), ['slug' => 'msp1']);

        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertSame(4, $payload['meta']['returned']);
        $this->assertTrue($payload['meta']['truncated']);
        $this->assertEqualsWithDelta(2.5, $payload['meta']['effective_step'], 0.01);
        $hours = array_map(
            static fn (array $p): string => substr((string) preg_replace('/^.*files\//', '', $p['url']), 11, 2),
            $payload['photos']
        );
        $this->assertSame(['00', '03', '06', '09'], $hours);
    }

    public function testListPhotosReturnsAllPhotosInChronologicalOrderWhenUnderLimit(): void
    {
        foreach (['10', '02', '07'] as $h) {
            file_put_contents($this->tmpDir . '/' . "2026-06-01_{$h}-00-00_photo.jpg", 'fake');
        }

        $response = $this->makeController()->listPhotos($this->requestWithQuery([
            'from' => '2026-06-01T00:00:00',
            'to' => '2026-06-01T23:59:59',
        ]), new Response(), ['slug' => 'msp1']);

        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['meta']['truncated']);
        $this->assertCount(3, $payload['photos']);
        $this->assertStringContainsString('_02-00-00_', $payload['photos'][0]['url']);
        $this->assertStringContainsString('_10-00-00_', $payload['photos'][2]['url']);
    }

    public function testListPhotosAppendsVersionTokenOfServedFile(): void
    {
        $name = '2026-06-01_12-00-00_photo.jpg';
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, 'fake');

        $normalize = $this->createMock(GalleryNormalizeService::class);
        $normalize->method('resolveDisplayPath')->willReturn($path);

        $response = $this->makeController($normalize)->listPhotos($this->requestWithQuery([
            'from' => '2026-06-01T00:00:00',
            'to' => '2026-06-01T23:59:59',
        ]), new Response(), ['slug' => 'msp1']);

        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        $expected = '?v=' . dechex((int) filemtime($path)) . '-' . dechex((int) filesize($path));
        $this->assertStringEndsWith($name . $expected, $payload['photos'][0]['url']);
    }

    public function testUniformSampleIndicesIncludeBothEnds(): void
    {
        $this->assertSame([], GalleryViewController::uniformSampleIndices(0, 5));
        $this->assertSame([0, 1, 2], GalleryViewController::uniformSampleIndices(3, 10));
        $this->assertSame([9], GalleryViewController::uniformSampleIndices(10, 1));
        $this->assertSame([0, 5, 9], GalleryViewController::uniformSampleIndices(10, 3));
        $indices = GalleryViewController::uniformSampleIndices(26000, 800);
        $this->assertCount(800, $indices);
        $this->assertSame(0, $indices[0]);
        $this->assertSame(25999, $indices[799]);
        $this->assertSame($indices, array_values(array_unique($indices)));
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

    private function makeController(?GalleryNormalizeService $normalize = null): GalleryViewController
    {
        $renderer = $this->createMock(TemplateRenderer::class);
        $galleryRepo = $this->createMock(GalleryControlRepository::class);
        $navRepo = $this->createMock(NavPageRepository::class);
        $normalize ??= $this->createMock(GalleryNormalizeService::class);

        return new GalleryViewController($renderer, $galleryRepo, $navRepo, $normalize);
    }

    /** @param array<string, string> $query */
    private function requestWithQuery(array $query): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $uri = 'http://localhost/api/gallery/msp1/photos?' . http_build_query($query);

        return $factory->createServerRequest('GET', $uri)->withQueryParams($query);
    }
}
