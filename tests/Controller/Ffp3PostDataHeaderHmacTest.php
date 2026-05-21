<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\Ffp3\PostDataController;
use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorRepository;
use App\Security\SignatureValidator;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class Ffp3PostDataHeaderHmacTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $oldEnv = [];

    protected function setUp(): void
    {
        foreach (['API_SIG_SECRET', 'SIG_VALID_WINDOW', 'HMAC_STRICT_MODE', 'HMAC_NONCE_REQUIRED'] as $key) {
            $this->oldEnv[$key] = $_ENV[$key] ?? null;
        }

        $_ENV['API_SIG_SECRET'] = 'unit-test-secret';
        $_ENV['SIG_VALID_WINDOW'] = '300';
        $_ENV['HMAC_STRICT_MODE'] = 'true';
        $_ENV['HMAC_NONCE_REQUIRED'] = 'false';
    }

    protected function tearDown(): void
    {
        foreach ($this->oldEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testFfp5csHeaderHmacAuthenticatesWithoutApiKey(): void
    {
        $controller = $this->createController();
        $body = 'sensor=ffp3&version=13.80&api_key=legacy';
        $request = $this->signedRequest($body);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->callValidate($request, [
            'sensor' => 'ffp3',
            'version' => '13.80',
        ], $response);

        $this->assertNull($result);
        $this->assertFalse($controller->exposesRequiresApiKey());
    }

    public function testFfp5csHeaderHmacRejectsTamperedBody(): void
    {
        $controller = $this->createController();
        $signedBody = 'sensor=ffp3&version=13.80&api_key=legacy';
        $tamperedBody = $signedBody . '&TempAir=99';
        $request = $this->signedRequest($tamperedBody, $signedBody);
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->callValidate($request, [
            'sensor' => 'ffp3',
            'version' => '13.80',
        ], $response);

        $this->assertNotNull($result);
        $this->assertSame(401, $result->getStatusCode());
        $this->assertTrue($controller->exposesRequiresApiKey());
    }

    private function createController(): TestableFfp3PostDataHeaderHmacController
    {
        return new TestableFfp3PostDataHeaderHmacController(
            $this->createMock(LogService::class),
            $this->createMock(ErrorAlertService::class),
            $this->createMock(SensorRepository::class),
            $this->createMock(OutputRepository::class),
            $this->createMock(BoardRepository::class),
        );
    }

    private function signedRequest(string $body, ?string $bodyToSign = null): ServerRequestInterface
    {
        $timestamp = (string) time();
        $nonce = 'nonce-ffp5cs-test';
        $signature = SignatureValidator::createSignatureForBody(
            (int) $timestamp,
            $nonce,
            $bodyToSign ?? $body,
            'unit-test-secret'
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/ffp3/post-data')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('X-Sig-Timestamp', $timestamp)
            ->withHeader('X-Sig-Nonce', $nonce)
            ->withHeader('X-Sig-Hmac', $signature);
        $request->getBody()->write($body);
        $request->getBody()->rewind();

        return $request;
    }
}

/**
 * @internal
 */
final class TestableFfp3PostDataHeaderHmacController extends PostDataController
{
    /**
     * @param array<string, mixed> $params
     */
    public function callValidate(
        ServerRequestInterface $request,
        array $params,
        ResponseInterface $response
    ): ?ResponseInterface {
        return $this->validateAuth($request, $params, $response);
    }

    public function exposesRequiresApiKey(): bool
    {
        return $this->requiresApiKey();
    }
}
