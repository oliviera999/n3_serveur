<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\AbstractPostDataController;
use App\Controller\Concerns\HmacAuthTrait;
use App\Security\SignatureValidator;
use App\Service\LogService;
use App\Util\ResponseHelper;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Response;

/**
 * Tests du trait HmacAuthTrait utilise par MspPostDataController et
 * N3ppPostDataController pour l'auth HMAC FFP3-compatible (Phase 4 audit).
 */
class HmacAuthTraitTest extends TestCase
{
    private LogService $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LogService::class);
        $_ENV['API_SIG_SECRET'] = 'unit-test-secret';
        $_ENV['SIG_VALID_WINDOW'] = '300';
        $_ENV['API_KEY'] = 'unit-test-api-key';
    }

    public function testNoHmacFallsBackToApiKey(): void
    {
        $ctrl = new HmacAuthTraitTestController($this->logger);
        $response = $this->newResponse();

        // Aucun timestamp/signature : retour null (fallback api_key géré par parent).
        $result = $ctrl->callValidate(['sensor' => 'msp1'], $response);
        $this->assertNull($result);
        $this->assertFalse($ctrl->exposeAuthenticatedByHmac());
    }

    public function testValidHmacAuthenticates(): void
    {
        $ctrl = new HmacAuthTraitTestController($this->logger);
        $response = $this->newResponse();

        $timestamp = (string) time();
        $signature = SignatureValidator::createSignature((int) $timestamp, 'unit-test-secret');

        $result = $ctrl->callValidate([
            'timestamp' => $timestamp,
            'signature' => $signature,
            'sensor' => 'msp1',
        ], $response);

        $this->assertNull($result);
        $this->assertTrue($ctrl->exposeAuthenticatedByHmac());
        $this->assertFalse($ctrl->exposesRequiresApiKey());
    }

    public function testInvalidSignatureRejected(): void
    {
        $ctrl = new HmacAuthTraitTestController($this->logger);
        $response = $this->newResponse();

        $result = $ctrl->callValidate([
            'timestamp' => (string) time(),
            'signature' => 'badsignature',
            'sensor' => 'msp1',
        ], $response);

        $this->assertNotNull($result);
        $this->assertSame(401, $result->getStatusCode());
        $this->assertFalse($ctrl->exposeAuthenticatedByHmac());
    }

    public function testIncompleteSignatureRejected(): void
    {
        $ctrl = new HmacAuthTraitTestController($this->logger);
        $response = $this->newResponse();

        $result = $ctrl->callValidate([
            'timestamp' => (string) time(),
            'sensor' => 'msp1',
        ], $response);

        $this->assertNotNull($result);
        $this->assertSame(401, $result->getStatusCode());
    }

    public function testMissingSecretReturnsServerError(): void
    {
        $_ENV['API_SIG_SECRET'] = '';
        $ctrl = new HmacAuthTraitTestController($this->logger);
        $response = $this->newResponse();

        $result = $ctrl->callValidate([
            'timestamp' => (string) time(),
            'signature' => str_repeat('a', 64),
            'sensor' => 'msp1',
        ], $response);

        $this->assertNotNull($result);
        $this->assertSame(500, $result->getStatusCode());
    }

    private function newResponse(): Response
    {
        return (new ResponseFactory())->createResponse();
    }
}

/**
 * Controller fictif utilisant le trait pour les tests.
 * @internal
 */
final class HmacAuthTraitTestController extends AbstractPostDataController
{
    use HmacAuthTrait;

    public function componentName(): string
    {
        return 'TestHmac';
    }

    protected function buildSensorData(
        array $params,
        \Closure $sanitize,
        \Closure $toFloat,
        \Closure $toInt
    ): object {
        return new \stdClass();
    }

    protected function insertData(object $data): void
    {
    }

    public function callValidate(array $params, \Psr\Http\Message\ResponseInterface $response): ?\Psr\Http\Message\ResponseInterface
    {
        return $this->validateHmacOrFallback($params, $response);
    }

    public function exposeAuthenticatedByHmac(): bool
    {
        return $this->authenticatedByHmac;
    }

    public function exposesRequiresApiKey(): bool
    {
        return $this->requiresApiKey();
    }
}
