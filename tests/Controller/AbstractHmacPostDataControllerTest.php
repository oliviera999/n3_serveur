<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\AbstractHmacPostDataController;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Tests de la base AbstractHmacPostDataController (factorisation Msp/N3pp) :
 * normalisation de l'email firmware et delegation HMAC de validateAuth().
 * La logique HMAC elle-meme est testee par HmacAuthTraitTest.
 */
class AbstractHmacPostDataControllerTest extends TestCase
{
    private ?string $oldStrict = null;

    protected function setUp(): void
    {
        $this->oldStrict = $_ENV['HMAC_STRICT_MODE'] ?? null;
        $_ENV['HMAC_STRICT_MODE'] = 'false';
    }

    protected function tearDown(): void
    {
        if ($this->oldStrict === null) {
            unset($_ENV['HMAC_STRICT_MODE']);
        } else {
            $_ENV['HMAC_STRICT_MODE'] = $this->oldStrict;
        }
    }

    private function sanitize(array $params): \Closure
    {
        return fn (string $key): ?string =>
            isset($params[$key]) && is_scalar($params[$key]) ? trim((string) $params[$key]) : null;
    }

    public function testValidEmailIsKept(): void
    {
        $logger = $this->createMock(LogService::class);
        $logger->expects($this->never())->method('warning');
        $ctrl = new HmacPostDataTestController($logger);

        $params = ['mail' => 'alice@example.com'];
        $this->assertSame('alice@example.com', $ctrl->callSanitizeEmail($params, $this->sanitize($params)));
    }

    public function testInvalidEmailIsBlankedAndLogged(): void
    {
        $logger = $this->createMock(LogService::class);
        $logger->expects($this->once())->method('warning');
        $ctrl = new HmacPostDataTestController($logger);

        $params = ['mail' => 'pas-un-email', 'sensor' => 'msp1', 'version' => '2.42'];
        $this->assertSame('', $ctrl->callSanitizeEmail($params, $this->sanitize($params)));
    }

    public function testAbsentEmailStaysNull(): void
    {
        $logger = $this->createMock(LogService::class);
        $logger->expects($this->never())->method('warning');
        $ctrl = new HmacPostDataTestController($logger);

        $params = [];
        $this->assertNull($ctrl->callSanitizeEmail($params, $this->sanitize($params)));
    }

    public function testEmptyEmailStaysEmptyWithoutWarning(): void
    {
        $logger = $this->createMock(LogService::class);
        $logger->expects($this->never())->method('warning');
        $ctrl = new HmacPostDataTestController($logger);

        $params = ['mail' => ''];
        $this->assertSame('', $ctrl->callSanitizeEmail($params, $this->sanitize($params)));
    }

    public function testValidateAuthFallsBackWhenNoHmac(): void
    {
        $logger = $this->createMock(LogService::class);
        $ctrl = new HmacPostDataTestController($logger);
        $response = (new ResponseFactory())->createResponse();

        // Ni timestamp ni signature -> delegation HMAC retourne null (fallback api_key).
        $this->assertNull($ctrl->callValidateAuth(['sensor' => 'msp1'], $response));
    }
}

/**
 * Sous-classe concrete minimale exposant les methodes protegees a tester.
 */
class HmacPostDataTestController extends AbstractHmacPostDataController
{
    protected function componentName(): string
    {
        return 'TestPostData';
    }

    protected function buildSensorData(array $params, \Closure $sanitize, \Closure $toFloat, \Closure $toInt): object
    {
        return (object) [];
    }

    protected function insertData(object $data): void
    {
    }

    public function callSanitizeEmail(array $params, \Closure $sanitize): ?string
    {
        return $this->sanitizeFirmwareEmail($params, $sanitize);
    }

    public function callValidateAuth(array $params, \Psr\Http\Message\ResponseInterface $response): ?\Psr\Http\Message\ResponseInterface
    {
        return $this->validateAuth($params, $response);
    }
}
