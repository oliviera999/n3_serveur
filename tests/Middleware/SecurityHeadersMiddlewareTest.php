<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Couvre les headers de sécurité critiques :
 *   - X-Content-Type-Options, X-Frame-Options, Referrer-Policy
 *   - Permissions-Policy, Content-Security-Policy
 *   - HSTS (HTTPS direct, X-Forwarded-Proto, SECURITY_FORCE_HSTS)
 */
final class SecurityHeadersMiddlewareTest extends TestCase
{
    private RequestHandlerInterface $passthroughHandler;
    private ?string $previousForceHsts;
    private ?string $previousCsp;

    protected function setUp(): void
    {
        $this->passthroughHandler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $this->previousForceHsts = $_ENV['SECURITY_FORCE_HSTS'] ?? null;
        $this->previousCsp = $_ENV['SECURITY_CSP'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousForceHsts === null) {
            unset($_ENV['SECURITY_FORCE_HSTS']);
        } else {
            $_ENV['SECURITY_FORCE_HSTS'] = $this->previousForceHsts;
        }
        if ($this->previousCsp === null) {
            unset($_ENV['SECURITY_CSP']);
        } else {
            $_ENV['SECURITY_CSP'] = $this->previousCsp;
        }
    }

    private function makeRequest(string $url, array $headers = []): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $req = $factory->createServerRequest('GET', $url);
        foreach ($headers as $name => $value) {
            $req = $req->withHeader($name, $value);
        }
        return $req;
    }

    public function testCoreHeadersAlwaysPresent(): void
    {
        $_ENV['SECURITY_FORCE_HSTS'] = 'false';
        $middleware = new SecurityHeadersMiddleware();
        $response = $middleware->process(
            $this->makeRequest('http://localhost/'),
            $this->passthroughHandler
        );

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame(
            'strict-origin-when-cross-origin',
            $response->getHeaderLine('Referrer-Policy')
        );
        $this->assertStringContainsString(
            'geolocation=()',
            $response->getHeaderLine('Permissions-Policy')
        );
        $this->assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testHstsSetWhenHttps(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $response = $middleware->process(
            $this->makeRequest('https://iot.olution.info/'),
            $this->passthroughHandler
        );
        $this->assertStringContainsString(
            'max-age=31536000',
            $response->getHeaderLine('Strict-Transport-Security')
        );
    }

    public function testHstsSetWhenForwardedProtoHttps(): void
    {
        $_ENV['SECURITY_FORCE_HSTS'] = 'false';
        $middleware = new SecurityHeadersMiddleware();
        $response = $middleware->process(
            $this->makeRequest('http://localhost/', ['X-Forwarded-Proto' => 'https']),
            $this->passthroughHandler
        );
        $this->assertStringContainsString(
            'max-age=31536000',
            $response->getHeaderLine('Strict-Transport-Security')
        );
    }

    public function testHstsAbsentInPlainHttpWithoutForceFlag(): void
    {
        $_ENV['SECURITY_FORCE_HSTS'] = 'false';
        $middleware = new SecurityHeadersMiddleware();
        $response = $middleware->process(
            $this->makeRequest('http://localhost/'),
            $this->passthroughHandler
        );
        $this->assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function testHstsForcedByEnvFlag(): void
    {
        $_ENV['SECURITY_FORCE_HSTS'] = 'true';
        $middleware = new SecurityHeadersMiddleware();
        $response = $middleware->process(
            $this->makeRequest('http://localhost/'),
            $this->passthroughHandler
        );
        $this->assertNotSame('', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testDefaultCspAllowsGoogleSheetsIframes(): void
    {
        unset($_ENV['SECURITY_CSP']);
        $middleware = new SecurityHeadersMiddleware();
        $response = $middleware->process(
            $this->makeRequest('http://localhost/'),
            $this->passthroughHandler
        );
        // Sans frame-src explicite, les iframes Google Sheets (pubchart) sont
        // bloquees par le fallback default-src 'self'.
        $this->assertStringContainsString(
            'frame-src',
            $response->getHeaderLine('Content-Security-Policy')
        );
        $this->assertStringContainsString(
            'https://docs.google.com',
            $response->getHeaderLine('Content-Security-Policy')
        );
    }

    public function testDefaultCspAllowsGoogleFonts(): void
    {
        unset($_ENV['SECURITY_CSP']);
        $middleware = new SecurityHeadersMiddleware();
        $response = $middleware->process(
            $this->makeRequest('http://localhost/'),
            $this->passthroughHandler
        );
        $csp = $response->getHeaderLine('Content-Security-Policy');

        // La feuille de style Google Fonts (layout.twig) est chargee depuis
        // fonts.googleapis.com ; sans cette origine dans style-src elle est bloquee.
        $this->assertMatchesRegularExpression(
            '/style-src[^;]*https:\/\/fonts\.googleapis\.com/',
            $csp
        );
        // Les fichiers de police viennent de fonts.gstatic.com : ils doivent etre
        // autorises dans font-src.
        $this->assertMatchesRegularExpression(
            '/font-src[^;]*https:\/\/fonts\.gstatic\.com/',
            $csp
        );
    }

    public function testCustomCspOverridesDefault(): void
    {
        $_ENV['SECURITY_CSP'] = "default-src 'none'";
        $middleware = new SecurityHeadersMiddleware();
        $response = $middleware->process(
            $this->makeRequest('http://localhost/'),
            $this->passthroughHandler
        );
        $this->assertSame("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }
}
