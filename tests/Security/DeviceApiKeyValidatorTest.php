<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Security\DeviceApiKeyValidator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class DeviceApiKeyValidatorTest extends TestCase
{
    private string $previousApiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApiKey = $_ENV['API_KEY'] ?? '';
        $_ENV['API_KEY'] = 'test-device-key-12345';
    }

    protected function tearDown(): void
    {
        $_ENV['API_KEY'] = $this->previousApiKey;
        parent::tearDown();
    }

    public function testValidHeader(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/gallery/msp1/api/outputs/state')
            ->withHeader('X-Api-Key', 'test-device-key-12345');

        $this->assertTrue(DeviceApiKeyValidator::isValidRequest($request));
    }

    public function testValidQueryParam(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/msp1gallery/uploadphotoserver-outputs-action.php?api_key=test-device-key-12345');

        $this->assertTrue(DeviceApiKeyValidator::isValidRequest($request, ['api_key' => 'test-device-key-12345']));
    }

    public function testRejectsInvalidKey(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/gallery/msp1/api/outputs/state')
            ->withHeader('X-Api-Key', 'wrong');

        $this->assertFalse(DeviceApiKeyValidator::isValidRequest($request));
    }
}
