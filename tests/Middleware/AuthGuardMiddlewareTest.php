<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\AuthGuardMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\TokenAuthMiddleware;
use App\Security\AuthService;
use App\Security\RoleAccessService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Vérifie la logique centrale du garde d'authentification (chemins protégés, session).
 */
final class AuthGuardMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $routesConfig;

    protected function setUp(): void
    {
        $configPath = dirname(__DIR__, 2) . '/config/routes_config.php';
        if (!is_file($configPath)) {
            $this->markTestSkipped('routes_config.php absent');
        }
        $this->routesConfig = require $configPath;
    }

    public function testProtectedControlPageRedirectsWhenUnauthenticated(): void
    {
        $paths = [
            '/aquaponie-control',
            '/meteo-control',
            '/serre-control',
            '/msp1-test/msp1control/',
            '/n3pp-test/n3ppcontrol/',
        ];

        foreach ($paths as $path) {
            $guard = $this->createGuard(authenticated: false);
            $response = $guard->processProtectedPaths(
                $this->requestForPath($path),
                $this->passThroughHandler(),
                'session',
                $this->routesConfig
            );

            $this->assertSame(302, $response->getStatusCode(), "Chemin {$path} doit rediriger vers /login");
            $this->assertStringContainsString('/login', $response->getHeaderLine('Location'));
            $this->assertStringContainsString(urlencode(rtrim($path, '/')), $response->getHeaderLine('Location'));
        }
    }

    public function testVitrinePagesPassWithoutAuthentication(): void
    {
        $paths = ['/aquaponie', '/meteo', '/serre', '/gallery'];

        foreach ($paths as $path) {
            $guard = $this->createGuard(authenticated: false);
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->expects($this->once())->method('handle')->willReturn(new Response());

            $response = $guard->processProtectedPaths(
                $this->requestForPath($path),
                $handler,
                'session',
                $this->routesConfig
            );

            $this->assertSame(200, $response->getStatusCode(), "Chemin vitrine {$path} doit rester public");
        }
    }

    public function testApplyConfiguredAuthSessionRedirectsWhenUnauthenticated(): void
    {
        $guard = $this->createGuard(authenticated: false);

        $authMiddleware = $this->createMock(AuthMiddleware::class);
        $authMiddleware->expects($this->once())
            ->method('process')
            ->willReturn((new Response())->withStatus(302)->withHeader('Location', '/login'));

        $guard = new AuthGuardMiddleware(
            $this->unauthenticatedAuthService(),
            $authMiddleware,
            $this->createMock(TokenAuthMiddleware::class),
            new RoleAccessService($this->routesConfig),
        );

        $response = $guard->applyConfiguredAuth(
            $this->requestForPath('/aquaponie-control'),
            $this->passThroughHandler(),
            'session'
        );

        $this->assertSame(302, $response->getStatusCode());
    }

    private function createGuard(bool $authenticated): AuthGuardMiddleware
    {
        $authService = $authenticated ? $this->authenticatedAuthService() : $this->unauthenticatedAuthService();

        return new AuthGuardMiddleware(
            $authService,
            $this->createMock(AuthMiddleware::class),
            $this->createMock(TokenAuthMiddleware::class),
            new RoleAccessService($this->routesConfig),
        );
    }

    private function unauthenticatedAuthService(): AuthService
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('isAuthenticated')->willReturn(false);
        $auth->method('isAuthenticatedByToken')->willReturn(false);
        $auth->method('getCurrentRole')->willReturn(null);

        return $auth;
    }

    private function authenticatedAuthService(): AuthService
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('isAuthenticated')->willReturn(true);
        $auth->method('getCurrentRole')->willReturn('operator');

        return $auth;
    }

    private function requestForPath(string $path): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        return $handler;
    }
}
