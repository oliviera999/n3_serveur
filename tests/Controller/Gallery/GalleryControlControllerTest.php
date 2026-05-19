<?php

declare(strict_types=1);

namespace Tests\Controller\Gallery;

use App\Controller\Gallery\GalleryControlController;
use App\Repository\GalleryControlRepository;
use App\Security\AuthService;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class GalleryControlControllerTest extends TestCase
{
    private string $previousApiKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApiKey = $_ENV['API_KEY'] ?? '';
        $_ENV['API_KEY'] = 'test-device-key';
        putenv('API_KEY=test-device-key');
    }

    protected function tearDown(): void
    {
        $_ENV['API_KEY'] = $this->previousApiKey;
        putenv('API_KEY=' . $this->previousApiKey);
        parent::tearDown();
    }

    private function createController(GalleryControlRepository $repository): GalleryControlController
    {
        $renderer = $this->createMock(TemplateRenderer::class);
        $authService = $this->createMock(AuthService::class);
        $logger = $this->createMock(LogService::class);

        return new GalleryControlController($repository, $renderer, $authService, $logger);
    }

    public function testRejectsMismatchedBoardOnStateEndpoint(): void
    {
        $repository = $this->createMock(GalleryControlRepository::class);
        $repository->method('getModule')->willReturn(['board' => 5, 'table' => 'UploadPhoto1Outputs', 'label' => 'FFP3']);
        $repository->expects($this->never())->method('getStateForFirmware');

        $controller = $this->createController($repository);
        $apiKey = (string) ($_ENV['API_KEY'] ?? 'test-device-key');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/gallery/ffp3/api/outputs/state?board=7&api_key=' . urlencode($apiKey))
            ->withHeader('X-Api-Key', $apiKey);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->getStateBySlug($request, $response, ['slug' => 'ffp3']);
        $this->assertContains($result->getStatusCode(), [400, 401]);
        if ($result->getStatusCode() === 400) {
            $this->assertStringContainsString('Board incompatible', (string) $result->getBody());
        } else {
            $this->assertStringContainsString('Cle API invalide', (string) $result->getBody());
        }
    }

    public function testRejectsMismatchedSensorOnFirmwareVersionPost(): void
    {
        $repository = $this->createMock(GalleryControlRepository::class);
        $repository->method('getModule')->willReturn(['board' => 5, 'table' => 'UploadPhoto1Outputs', 'label' => 'FFP3']);
        $repository->expects($this->never())->method('updateFirmwareVersion');

        $controller = $this->createController($repository);
        $apiKey = (string) ($_ENV['API_KEY'] ?? 'test-device-key');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/api/firmware/version')
            ->withHeader('X-Api-Key', $apiKey)
            ->withParsedBody([
                'version' => '2.38',
                'api_key' => $apiKey,
                'board' => '5',
                'sensor' => 'n3pp',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->postFirmwareVersionBySlug($request, $response, ['slug' => 'ffp3']);
        $this->assertSame(400, $result->getStatusCode());
        $this->assertStringContainsString('Sensor incompatible', (string) $result->getBody());
    }
}
