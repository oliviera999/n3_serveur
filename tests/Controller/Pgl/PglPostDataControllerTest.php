<?php

declare(strict_types=1);

namespace Tests\Controller\Pgl;

use App\Controller\Pgl\PglPostDataController;
use App\Repository\PglRepository;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PglPostDataControllerTest extends TestCase
{
    private string $previousApiKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApiKey = $_ENV['PGL_API_KEY'] ?? '';
        $_ENV['PGL_API_KEY'] = 'test-pgl-key';
    }

    protected function tearDown(): void
    {
        $_ENV['PGL_API_KEY'] = $this->previousApiKey;
        parent::tearDown();
    }

    public function testRejectsInvalidApiKey(): void
    {
        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->never())->method('insertEvent');

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'wrong',
                'events' => '1716123000:1:3:1:4020:-60',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(401, $result->getStatusCode());
    }

    public function testInsertsBatchWhenPayloadIsValid(): void
    {
        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->exactly(2))->method('insertEvent');

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'test-pgl-key',
                'sensor' => 'poissonglouton',
                'version' => '0.1.0',
                'events' => '1716123000:1:3:1:4020:-60,1716123010:1:1:0:4010:-61',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('"inserted": 2', (string) $result->getBody());
    }
}
