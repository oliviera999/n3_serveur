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
        $repo->expects($this->never())->method('insertEventIdempotent');

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

    public function testIdempotentInsertWithEventId(): void
    {
        $repo = $this->createMock(PglRepository::class);
        // Les deux événements ont un event_id → insertEventIdempotent appelé 2 fois
        $repo->expects($this->never())->method('insertEvent');
        $repo->expects($this->exactly(2))
             ->method('insertEventIdempotent')
             ->willReturn(true);

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'test-pgl-key',
                'sensor' => 'poissonglouton',
                'version' => '0.1.23',
                // Format : epoch:countDelta:sensorMode:tandemValidated:batteryMv:rssi:eventId
                'events' => '1716123000:1:3:1:4020:-60:42,1716123010:1:1:0:4010:-61:43',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(200, $result->getStatusCode());

        $body = (string) $result->getBody();
        $this->assertStringContainsString('"inserted": 2', $body);
        $this->assertStringContainsString('"last_acked_event_id": 43', $body);
    }

    public function testIdempotentInsertIgnoresDuplicates(): void
    {
        $repo = $this->createMock(PglRepository::class);
        // Premier retourne true (nouveau), second retourne false (doublon)
        $repo->expects($this->exactly(2))
             ->method('insertEventIdempotent')
             ->willReturnOnConsecutiveCalls(true, false);

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'test-pgl-key',
                'sensor' => 'poissonglouton',
                'version' => '0.1.23',
                'events' => '1716123000:1:1:0:4020:-60:10,1716123010:1:1:0:4010:-61:10',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(200, $result->getStatusCode());

        $body = (string) $result->getBody();
        // 1 inséré, 1 ignoré (doublon) — mais last_acked_event_id = 10
        $this->assertStringContainsString('"inserted": 1', $body);
        $this->assertStringContainsString('"last_acked_event_id": 10', $body);
    }

    public function testMixedLegacyAndIdempotentEvents(): void
    {
        $repo = $this->createMock(PglRepository::class);
        // Premier événement sans event_id (legacy) → insertEvent
        $repo->expects($this->once())->method('insertEvent');
        // Second événement avec event_id → insertEventIdempotent
        $repo->expects($this->once())
             ->method('insertEventIdempotent')
             ->willReturn(true);

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'test-pgl-key',
                'sensor' => 'poissonglouton',
                'version' => '0.1.23',
                // Premier sans event_id (6 champs), second avec (7 champs)
                'events' => '1716123000:1:1:0:4020:-60,1716123010:1:3:1:4010:-61:55',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(200, $result->getStatusCode());

        $body = (string) $result->getBody();
        $this->assertStringContainsString('"inserted": 2', $body);
        $this->assertStringContainsString('"last_acked_event_id": 55', $body);
    }

    public function testMapsModeBitmaskPirAndIr(): void
    {
        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->once())
            ->method('insertEvent')
            ->with(
                'poissonglouton',
                $this->anything(),
                1,
                'ir_pir',
                false,
                $this->anything(),
                $this->anything(),
                '0.2.0'
            );

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'test-pgl-key',
                'sensor' => 'poissonglouton',
                'version' => '0.2.0',
                'events' => '1716123000:1:5:0:4020:-60',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(200, $result->getStatusCode());
    }
}
