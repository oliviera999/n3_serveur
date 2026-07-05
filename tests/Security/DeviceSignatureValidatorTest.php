<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Security\DeviceSignatureValidator;
use App\Security\SignatureValidator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * A4 (audit 2026-07-05) : validation additive de la signature HMAC des endpoints galerie.
 */
class DeviceSignatureValidatorTest extends TestCase
{
    private string $previousSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousSecret = $_ENV['API_SIG_SECRET'] ?? '';
        $_ENV['API_SIG_SECRET'] = 'sig-secret-abcdef';
        putenv('API_SIG_SECRET=sig-secret-abcdef');
    }

    protected function tearDown(): void
    {
        $_ENV['API_SIG_SECRET'] = $this->previousSecret;
        putenv('API_SIG_SECRET=' . $this->previousSecret);
        parent::tearDown();
    }

    public function testAbsentSignatureReturnsNull(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/msp1gallery/upload.php');
        $this->assertNull(DeviceSignatureValidator::verify($request, 'api-key'));
    }

    public function testValidSignatureReturnsTrue(): void
    {
        $body = 'api-key';
        $ts = (string) time();
        $nonce = $ts . '-1';
        $sig = SignatureValidator::createSignatureForBody((int) $ts, $nonce, $body, 'sig-secret-abcdef');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/msp1gallery/upload.php')
            ->withHeader('X-Sig-Timestamp', $ts)
            ->withHeader('X-Sig-Nonce', $nonce)
            ->withHeader('X-Sig-Hmac', $sig);

        $this->assertTrue(DeviceSignatureValidator::verify($request, $body));
    }

    public function testInvalidSignatureReturnsFalse(): void
    {
        $ts = (string) time();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/msp1gallery/upload.php')
            ->withHeader('X-Sig-Timestamp', $ts)
            ->withHeader('X-Sig-Nonce', $ts . '-1')
            ->withHeader('X-Sig-Hmac', str_repeat('0', 64));

        $this->assertFalse(DeviceSignatureValidator::verify($request, 'api-key'));
    }

    public function testExpiredTimestampReturnsFalse(): void
    {
        $body = 'api-key';
        $ts = (string) (time() - 4000);
        $nonce = $ts . '-1';
        $sig = SignatureValidator::createSignatureForBody((int) $ts, $nonce, $body, 'sig-secret-abcdef');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/msp1gallery/upload.php')
            ->withHeader('X-Sig-Timestamp', $ts)
            ->withHeader('X-Sig-Nonce', $nonce)
            ->withHeader('X-Sig-Hmac', $sig);

        $this->assertFalse(DeviceSignatureValidator::verify($request, $body));
    }

    public function testNoServerSecretIgnoresSignature(): void
    {
        // Serveur non configuré pour le HMAC : signature ignorée (rétro-compatibilité) -> null.
        $_ENV['API_SIG_SECRET'] = '';
        putenv('API_SIG_SECRET=');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/msp1gallery/upload.php')
            ->withHeader('X-Sig-Timestamp', (string) time())
            ->withHeader('X-Sig-Nonce', 'n-1')
            ->withHeader('X-Sig-Hmac', str_repeat('0', 64));

        $this->assertNull(DeviceSignatureValidator::verify($request, 'api-key'));
    }
}
