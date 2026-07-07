<?php

declare(strict_types=1);

namespace Tests\Controller\Pgl;

use App\Controller\Pgl\PglHeartbeatController;
use App\Repository\PglRepository;
use App\Security\SignatureValidator;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PglHeartbeatControllerTest extends TestCase
{
    /** @var array<string, string> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['PGL_API_KEY', 'API_KEY', 'API_SIG_SECRET', 'PGL_API_SIG_SECRET', 'HMAC_STRICT_MODE', 'SIG_VALID_WINDOW'] as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? '';
            unset($_ENV[$key]);
        }
        $_ENV['PGL_API_KEY'] = 'test-pgl-key';
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === '') {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testRejectsInvalidApiKey(): void
    {
        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->never())->method('insertHeartbeat');

        $controller = new PglHeartbeatController($this->createMock(LogService::class), null, null, null, $repo);
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

        $controller = new PglHeartbeatController($this->createMock(LogService::class), null, null, null, $repo);
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
            ->with(3600, 120000, 80000, 3, -65, 'poissonglouton', '0.1.2', null);

        $controller = new PglHeartbeatController($this->createMock(LogService::class), null, null, null, $repo);
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

    public function testStoresSensorsPresentWhenProvided(): void
    {
        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->once())
            ->method('insertHeartbeat')
            ->with(100, 50000, 40000, 1, null, null, null, 7);

        $controller = new PglHeartbeatController($this->createMock(LogService::class), null, null, null, $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/heartbeat')
            ->withParsedBody([
                'api_key' => 'test-pgl-key',
                'uptime' => '100',
                'free' => '50000',
                'min' => '40000',
                'reboots' => '1',
                'sensors_present' => '7',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testValidHmacAuthenticatesEvenWithWrongApiKey(): void
    {
        $_ENV['API_SIG_SECRET'] = 'pgl-hmac-secret';
        $ts = (string) time();
        $signature = SignatureValidator::createSignature((int) $ts, 'pgl-hmac-secret');

        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->once())->method('insertHeartbeat');

        $controller = new PglHeartbeatController($this->createMock(LogService::class), null, null, null, $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/heartbeat')
            ->withParsedBody([
                'api_key' => 'wrong-key', // ignoree : signature HMAC valide
                'uptime' => '100',
                'free' => '50000',
                'min' => '40000',
                'reboots' => '1',
                'timestamp' => $ts,
                'signature' => $signature,
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testInvalidHmacSignatureRejected(): void
    {
        $_ENV['API_SIG_SECRET'] = 'pgl-hmac-secret';

        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->never())->method('insertHeartbeat');

        $controller = new PglHeartbeatController($this->createMock(LogService::class), null, null, null, $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/heartbeat')
            ->withParsedBody([
                'api_key' => 'test-pgl-key',
                'uptime' => '100',
                'free' => '50000',
                'min' => '40000',
                'reboots' => '1',
                'timestamp' => (string) time(),
                'signature' => str_repeat('0', 64),
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(401, $result->getStatusCode());
    }
}
