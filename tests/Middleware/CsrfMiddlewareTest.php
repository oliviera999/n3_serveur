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
 *  - écriture authentifiée par ?token= ou en-tête admin : exemptée (non CSRF-able) ;
 *  - écriture portée par le seul cookie `admin_token` (AMBIANT) : jeton CSRF exigé.
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

    /**
     * @param bool $headerToken   Token admin valide en en-tête (`Authorization: Bearer`
     *                            / `X-Admin-Token`) — canal NON-ambiant.
     * @param bool $queryToken    Token admin valide en `?token=` — canal non-ambiant.
     * @param bool $ambientCookie Cookie `admin_token` valide — canal AMBIANT : depuis la
     *                            6.32.0 il ne doit PLUS exempter du jeton CSRF.
     */
    private function auth(bool $headerToken = false, bool $queryToken = false, bool $ambientCookie = false): AuthService
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('hasValidHeaderToken')->willReturn($headerToken);
        $auth->method('validateToken')->willReturn($queryToken);
        // isAuthenticatedByToken() englobe le cookie ambiant : le middleware ne doit
        // plus l'utiliser pour décider de l'exemption. On le laisse répondre vrai afin
        // que tout retour à cet appel fasse échouer le test de régression ci-dessous.
        $auth->method('isAuthenticatedByToken')->willReturn($headerToken || $queryToken || $ambientCookie);

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
        $middleware = new CsrfMiddleware($this->csrf, $this->auth());
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
        $middleware = new CsrfMiddleware($this->csrf, $this->auth());
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/pgl/post-data'),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    public function testProtectedPostWithoutTokenIsRejected(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->auth());

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/toggle'),
            $this->expectBlocked()
        );
        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('CSRF', (string) $result->getBody());
    }

    public function testProtectedPostWithValidHeaderTokenPasses(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->auth());
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/parameters', ['X-CSRF-Token' => $this->validToken]),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    public function testProtectedPostWithInvalidTokenIsRejected(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->auth());

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/toggle', ['X-CSRF-Token' => 'mauvais-jeton']),
            $this->expectBlocked()
        );
        $this->assertSame(403, $result->getStatusCode());
    }

    public function testValidAccessTokenExemptsFromCsrf(): void
    {
        // Auth par ?token= explicite : non vulnérable au CSRF → pas de token requis.
        $middleware = new CsrfMiddleware($this->csrf, $this->auth(queryToken: true));
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/toggle', [], ['token' => 'secret']),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    public function testValidHeaderAdminTokenExemptsFromCsrf(): void
    {
        // `X-Admin-Token` / `Authorization: Bearer` : le navigateur ne l'ajoute pas seul.
        $middleware = new CsrfMiddleware($this->csrf, $this->auth(headerToken: true));
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/toggle'),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    /**
     * RÉGRESSION 6.32.0 : le cookie `admin_token` est AMBIANT (posé 30 jours, réémis
     * automatiquement par le navigateur). Il ne prouve pas l'intention de l'utilisateur,
     * donc il ne doit pas exempter du jeton CSRF — c'est exactement ce contre quoi le
     * jeton protège. Avant le correctif, l'exemption passait par
     * `isAuthenticatedByToken()`, qui teste ce cookie en premier.
     */
    public function testAmbientCookieTokenDoesNotExemptFromCsrf(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->auth(ambientCookie: true));

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/toggle'),
            $this->expectBlocked()
        );
        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('CSRF', (string) $result->getBody());
    }

    /**
     * Le cookie ambiant ne dispense pas du jeton, mais ne le BLOQUE pas non plus :
     * avec un `X-CSRF-Token` valide, l'écriture passe normalement.
     */
    public function testAmbientCookieWithValidCsrfTokenPasses(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->auth(ambientCookie: true));
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/toggle', ['X-CSRF-Token' => $this->validToken]),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    public function testTokenAcceptedFromBodyField(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->auth());
        $passed = $this->createMock(ResponseInterface::class);

        $result = $middleware->process(
            $this->makeRequest('POST', '/api/outputs/parameters', [], [], ['_csrf_token' => $this->validToken]),
            $this->expectPass($passed)
        );
        $this->assertSame($passed, $result);
    }

    public function testTestEnvironmentEndpointIsProtected(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->auth());

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
        $middleware = new CsrfMiddleware($this->csrf, $this->auth());

        $result = $middleware->process(
            $this->makeRequest('POST', $path),
            $this->expectBlocked()
        );
        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString('CSRF', (string) $result->getBody());
    }

    public function testNotificationPolicyWithValidTokenPasses(): void
    {
        $middleware = new CsrfMiddleware($this->csrf, $this->auth());
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
