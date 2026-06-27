<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\CsrfMiddleware;
use App\Security\AuthService;
use App\Security\CsrfService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Couvre la protection CSRF des écritures navigateur du panneau de contrôle.
 *
 * Garanties vérifiées :
 *  - méthodes sûres et chemins non listés : jamais bloqués (ESP32 préservé) ;
 *  - écriture protégée par cookie de session : token CSRF exigé ;
 *  - écriture authentifiée par ?token= : exemptée (non CSRF-able).
 */
final class CsrfMiddlewareTest extends TestCase
{
    private CsrfService $csrf;
    private string $validToken;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $this->csrf = new CsrfService();
        $this->validToken = $this->csrf->getToken();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $query
     * @param array<string,mixed>|null $parsedBody
     */
    private function makeRequest(
        string $method,
        string $path,
        array $headers = [],
        array $query = [],
        ?array $parsedBody = null
    ): ServerRequestInterface {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getParsedBody')->willReturn($parsedBody);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => $headers[$name] ?? ''
        );

        return $request;
    }

    private function authToken(bool $valid): AuthService
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('isAuthenticatedByToken')->willReturn($valid);

        return $auth;
    }

    private function expectPass(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($response);

        return $handler;
    }

    private function expectBlocked(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        return $handler;
    }

    public function testSafeMethodPassesThrough(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->authToken(false));
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('GET', '/api/outputs/toggle'),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    public function testUnprotectedPathPassesThrough(): void
    {
        // Endpoint machine ESP32 : jamais soumis au CSRF.
        $middleware = new CsrfMiddleware($this->csrf, $this->authToken(false));
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/pgl/post-data'),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    public function testProtectedPostWithoutTokenIsRejected(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->authToken(false));

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/toggle'),
            $this->expectBlocked()
        );
        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('CSRF', (string) $result->getBody());
    }

    public function testProtectedPostWithValidHeaderTokenPasses(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->authToken(false));
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/parameters', ['X-CSRF-Token' => $this->validToken]),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    public function testProtectedPostWithInvalidTokenIsRejected(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->authToken(false));

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/toggle', ['X-CSRF-Token' => 'mauvais-jeton']),
            $this->expectBlocked()
        );
        $this->assertSame(403, $result->getStatusCode());
    }

    public function testValidAccessTokenExemptsFromCsrf(): void
    {
        // Auth par ?token= explicite : non vulnérable au CSRF → pas de token requis.
        $middleware = new CsrfMiddleware($this->csrf, $this->authToken(true));
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/toggle', [], ['token' => 'secret']),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    public function testTokenAcceptedFromBodyField(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->authToken(false));
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/parameters', [], [], ['_csrf_token' => $this->validToken]),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    public function testTestEnvironmentEndpointIsProtected(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->authToken(false));

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs-test/toggle-test'),
            $this->expectBlocked()
        );
        $this->assertSame(403, $result->getStatusCode());
    }

    /**
     * Régression : la sauvegarde de la politique de notifications (nouveau système
     * de mailing) est une écriture navigateur authentifiée par session → doit exiger
     * un token CSRF, comme /parameters et /toggle.
     *
     * @dataProvider notificationPolicyPaths
     */
    public function testNotificationPolicyEndpointIsProtected(string $path): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->authToken(false));

        $result = $middleware->process(
            $this->makeRequest('POST', $path),
            $this->expectBlocked()
        );
        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('CSRF', (string) $result->getBody());
    }

    public function testNotificationPolicyWithValidTokenPasses(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->authToken(false));
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/msp1/api/outputs/notification-policy', ['X-CSRF-Token' => $this->validToken]),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notificationPolicyPaths(): array
    {
        return [
            'ffp3 prod' => ['/api/outputs/notification-policy'],
            'msp1 prod' => ['/msp1/api/outputs/notification-policy'],
            'msp1 test' => ['/msp1-test/api/outputs/notification-policy'],
            'n3pp prod' => ['/n3pp/api/outputs/notification-policy'],
            'gallery' => ['/gallery/msp1/api/outputs/notification-policy'],
        ];
    }
}
