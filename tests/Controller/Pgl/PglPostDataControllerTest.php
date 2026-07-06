<?php

declare(strict_types=1);

namespace Tests\Controller\Pgl;

use App\Controller\Pgl\PglPostDataController;
use App\Middleware\RawPostBodyMiddleware;
use App\Repository\PglRepository;
use App\Security\SignatureValidator;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PglPostDataControllerTest extends TestCase
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

    public function testValidHmacAuthenticatesEvenWithWrongApiKey(): void
    {
        $_ENV['API_SIG_SECRET'] = 'pgl-hmac-secret';
        $ts = (string) time();
        $signature = SignatureValidator::createSignature((int) $ts, 'pgl-hmac-secret');

        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->once())->method('insertEvent');

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'wrong-key', // ignoree : signature HMAC valide
                'sensor' => 'poissonglouton',
                'version' => '0.5.16',
                'events' => '1716123000:1:3:1:4020:-60',
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
        $repo->expects($this->never())->method('insertEvent');
        $repo->expects($this->never())->method('insertEventIdempotent');

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'test-pgl-key',
                'sensor' => 'poissonglouton',
                'version' => '0.5.16',
                'events' => '1716123000:1:3:1:4020:-60',
                'timestamp' => (string) time(),
                'signature' => str_repeat('0', 64),
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(401, $result->getStatusCode());
    }

    public function testHmacSentButServerSecretMissingReturns500(): void
    {
        // Aucun API_SIG_SECRET/PGL_API_SIG_SECRET configure cote serveur.
        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->never())->method('insertEvent');

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'test-pgl-key',
                'sensor' => 'poissonglouton',
                'version' => '0.5.16',
                'events' => '1716123000:1:3:1:4020:-60',
                'timestamp' => (string) time(),
                'signature' => str_repeat('a', 64),
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(500, $result->getStatusCode());
    }

    public function testDedicatedPglSecretIsPreferredOverCommonSecret(): void
    {
        // Cle PGL dediee != cle commune : la signature doit valider avec la cle PGL.
        $_ENV['API_SIG_SECRET'] = 'common-secret';
        $_ENV['PGL_API_SIG_SECRET'] = 'pgl-dedicated-secret';
        $ts = (string) time();
        $signature = SignatureValidator::createSignature((int) $ts, 'pgl-dedicated-secret');

        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->once())->method('insertEvent');

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'wrong-key',
                'sensor' => 'poissonglouton',
                'version' => '0.5.16',
                'events' => '1716123000:1:3:1:4020:-60',
                'timestamp' => $ts,
                'signature' => $signature,
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testBodySigningHeadersAuthenticate(): void
    {
        $_ENV['API_SIG_SECRET'] = 'pgl-hmac-secret';
        $ts = (string) time();
        $nonce = $ts . '-1';
        $rawBody = 'api_key=wrong-key&sensor=poissonglouton&version=0.5.16&events=1716123000%3A1%3A3%3A1%3A4020%3A-60';
        $signature = SignatureValidator::createSignatureForBody((int) $ts, $nonce, $rawBody, 'pgl-hmac-secret');

        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->once())->method('insertEvent');

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $rawBody)
            ->withHeader('X-Sig-Timestamp', $ts)
            ->withHeader('X-Sig-Nonce', $nonce)
            ->withHeader('X-Sig-Hmac', $signature)
            ->withParsedBody([
                'api_key' => 'wrong-key',
                'sensor' => 'poissonglouton',
                'version' => '0.5.16',
                'events' => '1716123000:1:3:1:4020:-60',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testStrictModeRejectsMissingHmac(): void
    {
        $_ENV['API_SIG_SECRET'] = 'pgl-hmac-secret';
        $_ENV['HMAC_STRICT_MODE'] = 'true';

        $repo = $this->createMock(PglRepository::class);
        $repo->expects($this->never())->method('insertEvent');

        $controller = new PglPostDataController($this->createMock(LogService::class), $repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pgl/post-data')
            ->withParsedBody([
                'api_key' => 'test-pgl-key', // valide, mais strict exige HMAC
                'sensor' => 'poissonglouton',
                'version' => '0.5.16',
                'events' => '1716123000:1:3:1:4020:-60',
            ]);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->handle($request, $response);
        $this->assertSame(401, $result->getStatusCode());
    }
}
