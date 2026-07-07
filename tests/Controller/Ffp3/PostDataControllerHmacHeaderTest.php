<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Controller\Ffp3\PostDataController;
use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorRepository;
use App\Security\SignatureValidator;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

class PostDataControllerHmacHeaderTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $oldEnv = [];

    protected function setUp(): void
    {
        foreach (['API_SIG_SECRET', 'SIG_VALID_WINDOW', 'HMAC_STRICT_MODE', 'API_KEY'] as $key) {
            $this->oldEnv[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['API_SIG_SECRET'] = 'unit-test-secret';
        $_ENV['SIG_VALID_WINDOW'] = '300';
        $_ENV['HMAC_STRICT_MODE'] = 'true';
        $_ENV['API_KEY'] = '';
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

    public function testValidXSigHeadersAuthenticateWithoutApiKey(): void
    {
        $sensorRepo = $this->createMock(SensorRepository::class);
        $sensorRepo->expects($this->once())
            ->method('existsByPostId')
            ->with('post-hmac-1')
            ->willReturn(true);

        $controller = $this->createController($sensorRepo);
        $payload = 'sensor=ffp5cs&version=13.80&post_id=post-hmac-1';
        $request = $this->signedRequest($payload, [
            'sensor' => 'ffp5cs',
            'version' => '13.80',
            'post_id' => 'post-hmac-1',
        ]);

        $response = $controller->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Donnees deja enregistrees', (string) $response->getBody());
    }

    public function testInvalidXSigHeadersRejectedBeforeDeduplication(): void
    {
        $sensorRepo = $this->createMock(SensorRepository::class);
        $sensorRepo->expects($this->never())->method('existsByPostId');

        $controller = $this->createController($sensorRepo);
        $payload = 'sensor=ffp5cs&version=13.80&post_id=post-hmac-2';
        $request = $this->signedRequest($payload, [
            'sensor' => 'ffp5cs',
            'version' => '13.80',
            'post_id' => 'post-hmac-2',
        ], 'bad-signature');

        $response = $controller->handle($request, (new ResponseFactory())->createResponse());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Signature incorrecte', (string) $response->getBody());
    }

    private function createController(SensorRepository $sensorRepo): PostDataController
    {
        return new PostDataController(
            $this->createMock(LogService::class),
            null,
            null,
            null,
            $this->createMock(ErrorAlertService::class),
            $sensorRepo,
            $this->createMock(OutputRepository::class),
            $this->createMock(BoardRepository::class)
        );
    }

    /**
     * @param array<string, mixed> $parsedBody
     */
    private function signedRequest(string $payload, array $parsedBody, ?string $signatureOverride = null): \Psr\Http\Message\ServerRequestInterface
    {
        $timestamp = (string) time();
        $nonce = '0123456789abcdef0123456789abcdef';
        $signature = $signatureOverride
            ?? SignatureValidator::createSignatureForBody((int) $timestamp, $nonce, $payload, 'unit-test-secret');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/ffp3/post-data')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('X-Sig-Timestamp', $timestamp)
            ->withHeader('X-Sig-Nonce', $nonce)
            ->withHeader('X-Sig-Hmac', $signature)
            ->withParsedBody($parsedBody);

        return $request->withBody((new StreamFactory())->createStream($payload));
    }
}
