<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\AbstractPostDataController;
use App\Security\SignatureValidator;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie la logique auth HMAC vs api_key du contrat post-data.
 */
class AbstractPostDataAuthTest extends TestCase
{
    public function testHmacValidDoesNotRequireApiKey(): void
    {
        $logger = $this->createMock(LogService::class);
        $controller = new TestPostDataController($logger);

        $this->assertTrue($controller->exposesRequiresApiKey());
        $controller->simulateHmacSuccess();
        $this->assertFalse($controller->exposesRequiresApiKey());
    }

    public function testSignatureValidatorRejectsExpiredTimestamp(): void
    {
        $secret = 'unit-test-secret';
        $oldTs = (string) (time() - 600);
        $sig = hash_hmac('sha256', $oldTs, $secret);

        $this->assertFalse(SignatureValidator::isValid($oldTs, $sig, $secret, 300));
    }
}

/**
 * @internal
 */
final class TestPostDataController extends AbstractPostDataController
{
    protected function componentName(): string
    {
        return 'Test';
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

    public function exposesRequiresApiKey(): bool
    {
        return $this->requiresApiKey();
    }

    public function simulateHmacSuccess(): void
    {
        $this->authenticatedByHmac = true;
    }
}
