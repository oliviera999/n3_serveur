<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Config\Env;
use App\Middleware\TokenAuthMiddleware;
use App\Security\AuthService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;

/**
 * Couvre le garde d'authentification par token (cookie ou ?token=).
 * Critique côté sécurité : protège les routes d'administration.
 */
final class TokenAuthMiddlewareTest extends TestCase
{
    private string $previousToken;
    private bool $previousLoaded;

    protected function setUp(): void
    {
        $this->previousToken = $_ENV['ADMIN_TOKEN'] ?? '';
        // Bypass dotenv pour garder nos $_ENV de test (cf. AuthServiceTest).
        $ref = new ReflectionClass(Env::class);
        $prop = $ref->getProperty('loaded');
        $prop->setAccessible(true);
        $this->previousLoaded = (bool) $prop->getValue();
        $prop->setValue(null, true);

        $_ENV['ADMIN_TOKEN'] = 'jeton-secret';
        unset($_COOKIE['admin_token']);
    }

    protected function tearDown(): void
    {
        $_ENV['ADMIN_TOKEN'] = $this->previousToken;
        unset($_COOKIE['admin_token']);
        $ref = new ReflectionClass(Env::class);
        $prop = $ref->getProperty('loaded');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->previousLoaded);
    }

    public function testValidTokenInQueryLetsRequestThrough(): void
    {
        $middleware = new TokenAuthMiddleware(new AuthService());

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['token' => 'jeton-secret']);

        $passed = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->with($request)->willReturn($passed);

        $result = $middleware->process($request, $handler);
        $this->assertSame($passed, $result);
    }

    public function testMissingTokenRedirectsToLogin(): void
    {
        $middleware = new TokenAuthMiddleware(new AuthService());

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/clear-cache');
        $uri->method('getQuery')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getUri')->willReturn($uri);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $result = $middleware->process($request, $handler);
        $this->assertSame(302, $result->getStatusCode());
        $this->assertStringContainsString('/login', $result->getHeaderLine('Location'));
        $this->assertStringContainsString('redirect=', $result->getHeaderLine('Location'));
    }

    public function testWrongTokenRedirectsToLogin(): void
    {
        $middleware = new TokenAuthMiddleware(new AuthService());

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/clear-cache');
        $uri->method('getQuery')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['token' => 'mauvais']);
        $request->method('getUri')->willReturn($uri);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $result = $middleware->process($request, $handler);
        $this->assertSame(302, $result->getStatusCode());
    }
}
