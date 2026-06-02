<?php

declare(strict_types=1);

namespace Tests\Controller\Pgl;

use App\Controller\Pgl\PglHeartbeatController;
use App\Repository\PglRepository;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PglHeartbeatControllerTest extends TestCase
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
        $repo->expects($this->never())->method('insertHeartbeat');

        $controller = new PglHeartbeatController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/heartbeat')
            ->withParsedBody([
                'api_key' => 'wrong',
                'uptime' => '100',
                'free' => '50000',
                'min' => '40000',
                'reboots' => '1',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(401, $result->getStatusCode());
    }

    public function testRejectsMissingFields(): void
    {
        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->never())->method('insertHeartbeat');

        $controller = new PglHeartbeatController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/heartbeat')
            ->withParsedBody(['api_key' => 'test-pgl-key', 'uptime' => '100']);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(400, $result->getStatusCode());
    }

    public function testReturns200OnValidPayload(): void
    {
        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->once())
            ->method('insertHeartbeat')
            ->with(3600, 120000, 80000, 3, -65, 'poissonglouton', '0.1.2');

        $controller = new PglHeartbeatController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/heartbeat')
            ->withParsedBody([
                'api_key' => 'test-pgl-key',
                'sensor' => 'poissonglouton',
                'version' => '0.1.2',
                'uptime' => '3600',
                'free' => '120000',
                'min' => '80000',
                'reboots' => '3',
                'rssi' => '-65',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('OK', (string) $result->getBody());
    }
}
