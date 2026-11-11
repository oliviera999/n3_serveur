<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\McpController;
use App\Controller\OAuth\OAuthController;
use App\Mcp\McpConfig;
use App\Mcp\McpServer;
use App\Mcp\Support\ReadOnlyTransaction;
use App\Mcp\ToolRegistry;
use App\OAuth\AuthorizationServer;
use App\OAuth\ClientResolver;
use App\OAuth\HttpFetcher;
use App\OAuth\OAuthMetadata;
use App\OAuth\RedirectUriValidator;
use App\Repository\McpOAuthRepository;
use App\Repository\UserRepository;
use App\Security\RateLimiter;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Tests\Support\McpSqliteFixture;

/**
 * Couche HTTP du connecteur : 401 + WWW-Authenticate, métadonnées RFC 9728 / 8414,
 * 405, Origin, MCP-Protocol-Version, 202 pour les notifications, step-up 403,
 * corps form-urlencoded du /oauth/token, DCR strict.
 */
final class McpControllerTest extends TestCase
{
    private const BASE = 'https://iot.example.test';

    private PDO $pdo;
    private McpConfig $config;

    protected function setUp(): void
    {
        $this->pdo = McpSqliteFixture::pdo();
        $this->config = McpSqliteFixture::config();
    }

    private function authServer(?McpConfig $config = null): AuthorizationServer
    {
        return new AuthorizationServer(new McpOAuthRepository($this->pdo), new UserRepository($this->pdo), $config ?? $this->config);
    }

    private function controller(?McpConfig $config = null): McpController
    {
        $config ??= $this->config;
        $server = new McpServer(new ToolRegistry([], $config), new ReadOnlyTransaction($this->pdo), $this->createMock(LogService::class), $config);

        return new McpController($server, $this->authServer($config), new McpOAuthRepository($this->pdo), $config);
    }

    private ?RateLimiter $limiter = null;

    private function oauthController(?TemplateRenderer $renderer = null, ?McpConfig $config = null): OAuthController
    {
        $config ??= $this->config;
        $repo = new McpOAuthRepository($this->pdo);
        $fetcher = $this->createMock(HttpFetcher::class);

        return new OAuthController(
            $config,
            $this->authServer($config),
            new ClientResolver($repo, $fetcher, $config),
            new RedirectUriValidator($config),
            $repo,
            new UserRepository($this->pdo),
            $this->limiter ??= new RateLimiter(sys_get_temp_dir() . '/mcp-rl-' . bin2hex(random_bytes(4))),
            $renderer ?? $this->createMock(TemplateRenderer::class),
            $this->createMock(LogService::class)
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(string $method, string $path, string $body = '', array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://iot.example.test' . $path)
            ->withBody((new StreamFactory())->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function token(string $scope = 'iot.read'): string
    {
        return McpSqliteFixture::accessToken($this->pdo, 1, $scope, self::BASE . '/mcp');
    }

    public function testUnauthenticatedRequestGets401WithResourceMetadata(): void
    {
        $response = $this->controller()->post(self::request('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"initialize"}'), new Response());
        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith(
            'Bearer resource_metadata="' . self::BASE . '/.well-known/oauth-protected-resource/mcp"',
            $response->getHeaderLine('WWW-Authenticate')
        );
    }

    public function testInvalidTokenGets401WithInvalidTokenError(): void
    {
        $response = $this->controller()->post(self::request('POST', '/mcp', '{}', ['Authorization' => 'Bearer faux']), new Response());
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testWebAdminTokenIsNotAcceptedOnMcp(): void
    {
        $_ENV['ADMIN_TOKEN'] = 'jeton-admin-web';
        $response = $this->controller()->post(self::request('POST', '/mcp', '{}', ['Authorization' => 'Bearer jeton-admin-web']), new Response());
        self::assertSame(401, $response->getStatusCode());
        unset($_ENV['ADMIN_TOKEN']);
    }

    public function testAuthenticatedInitializeAndNotification(): void
    {
        $token = $this->token();
        $ok = $this->controller()->post(self::request('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}', [
            'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json',
        ]), new Response());
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('2025-03-26', json_decode((string) $ok->getBody(), true)['result']['protocolVersion']);

        $notif = $this->controller()->post(self::request('POST', '/mcp', '{"jsonrpc":"2.0","method":"notifications/initialized"}', [
            'Authorization' => 'Bearer ' . $token,
        ]), new Response());
        self::assertSame(202, $notif->getStatusCode());
        self::assertSame('', (string) $notif->getBody());
    }

    public function testUnsupportedProtocolHeaderAndParseError(): void
    {
        $token = $this->token();
        $bad = $this->controller()->post(self::request('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}', [
            'Authorization' => 'Bearer ' . $token, 'MCP-Protocol-Version' => '1999-01-01',
        ]), new Response());
        self::assertSame(400, $bad->getStatusCode());
        $parse = $this->controller()->post(self::request('POST', '/mcp', '{pas du json', ['Authorization' => 'Bearer ' . $token]), new Response());
        self::assertSame(-32700, json_decode((string) $parse->getBody(), true)['error']['code']);
    }

    public function testForeignOriginRejectedAndClaudeOriginAccepted(): void
    {
        $token = $this->token();
        $evil = $this->controller()->post(self::request('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}', [
            'Authorization' => 'Bearer ' . $token, 'Origin' => 'https://evil.example',
        ]), new Response());
        self::assertSame(403, $evil->getStatusCode());
        $claude = $this->controller()->post(self::request('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}', [
            'Authorization' => 'Bearer ' . $token, 'Origin' => 'https://claude.ai',
        ]), new Response());
        self::assertSame(200, $claude->getStatusCode());
    }

    public function testGetAndDeleteReturn405(): void
    {
        $response = $this->controller()->methodNotAllowed(self::request('GET', '/mcp'), new Response());
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    public function testProtectedResourceAndAuthorizationServerMetadata(): void
    {
        $prm = json_decode((string) $this->oauthController()->protectedResource(self::request('GET', '/.well-known/oauth-protected-resource/mcp'), new Response())->getBody(), true);
        self::assertSame(self::BASE . '/mcp', $prm['resource']);
        self::assertSame([self::BASE], $prm['authorization_servers']);
        self::assertSame(['header'], $prm['bearer_methods_supported']);
        // 6.61.0 : iot_migrate actif par défaut → iot.control annoncé même sans pilotage (apply exige ce scope).
        self::assertSame(['iot.read', 'iot.control'], $prm['scopes_supported'], 'iot.control annoncé (migrations actives par défaut)');

        $asm = OAuthMetadata::authorizationServer($this->config, self::BASE);
        self::assertSame(self::BASE, $asm['issuer']);
        self::assertSame(['code'], $asm['response_types_supported']);
        self::assertSame(['authorization_code', 'refresh_token'], $asm['grant_types_supported']);
        self::assertSame(['S256'], $asm['code_challenge_methods_supported']);
        self::assertContains('none', $asm['token_endpoint_auth_methods_supported']);
        self::assertTrue($asm['client_id_metadata_document_supported']);
        self::assertContains('offline_access', $asm['scopes_supported']);
        self::assertSame(self::BASE . '/oauth/register', $asm['registration_endpoint']);
        self::assertSame(self::BASE . '/oauth/revoke', $asm['revocation_endpoint']);
    }

    public function testDcrAcceptsClaudeAndLoopbackOnly(): void
    {
        $ok = $this->oauthController()->register(self::request('POST', '/oauth/register', (string) json_encode([
            'client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback', 'http://localhost:7777/callback'],
        ]), ['Content-Type' => 'application/json']), new Response());
        self::assertSame(201, $ok->getStatusCode());
        $body = json_decode((string) $ok->getBody(), true);
        self::assertStringStartsWith('mcp_', $body['client_id']);
        self::assertSame('none', $body['token_endpoint_auth_method']);

        $bad = $this->oauthController()->register(self::request('POST', '/oauth/register', (string) json_encode([
            'redirect_uris' => ['https://evil.example/callback'],
        ])), new Response());
        self::assertSame(400, $bad->getStatusCode());
        self::assertSame('invalid_redirect_uri', json_decode((string) $bad->getBody(), true)['error']);

        $confidential = $this->oauthController()->register(self::request('POST', '/oauth/register', (string) json_encode([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'token_endpoint_auth_method' => 'client_secret_post',
        ])), new Response());
        self::assertSame('invalid_client_metadata', json_decode((string) $confidential->getBody(), true)['error']);
    }

    public function testTokenEndpointParsesFormBodyAndReturnsRfcErrors(): void
    {
        $response = $this->oauthController()->token(self::request('POST', '/oauth/token', 'grant_type=refresh_token&refresh_token=inconnu&client_id=https%3A%2F%2Fclaude.ai%2Fx', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]), new Response());
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_grant', json_decode((string) $response->getBody(), true)['error']);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $grant = $this->oauthController()->token(self::request('POST', '/oauth/token', 'grant_type=password', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]), new Response());
        self::assertSame('unsupported_grant_type', json_decode((string) $grant->getBody(), true)['error']);
    }

    public function testConfidentialClientRequiresSecretOnTokenEndpoint(): void
    {
        $config = McpSqliteFixture::config([
            'MCP_OAUTH_CONFIDENTIAL_CLIENT_ID' => 'gemini-it',
            'MCP_OAUTH_CONFIDENTIAL_CLIENT_SECRET' => 'gemini-secret',
        ]);
        $oauth = $this->oauthController(null, $config);
        $server = $this->authServer($config);
        $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
        $challenge = \App\OAuth\Pkce::challengeFor($verifier);
        $code = $server->issueCode(
            'gemini-it',
            1,
            McpConfig::GEMINI_ENTERPRISE_CALLBACK,
            ['iot.read'],
            self::BASE . '/mcp',
            $challenge
        );

        $noSecret = $oauth->token(self::request(
            'POST',
            '/oauth/token',
            http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => McpConfig::GEMINI_ENTERPRISE_CALLBACK,
                'client_id' => 'gemini-it',
                'code_verifier' => $verifier,
            ]),
            ['Content-Type' => 'application/x-www-form-urlencoded']
        ), new Response());
        self::assertSame(401, $noSecret->getStatusCode());
        self::assertSame('invalid_client', json_decode((string) $noSecret->getBody(), true)['error']);

        // Nouveau code : le précédent a été consommé même en cas d'échec? exchangeCode n'est pas appelé si secret échoue avant.
        // Relancer avec secret (client_secret_post).
        $code2 = $server->issueCode(
            'gemini-it',
            1,
            McpConfig::GEMINI_ENTERPRISE_CALLBACK,
            ['iot.read'],
            self::BASE . '/mcp',
            $challenge
        );
        $ok = $oauth->token(self::request(
            'POST',
            '/oauth/token',
            http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code2,
                'redirect_uri' => McpConfig::GEMINI_ENTERPRISE_CALLBACK,
                'client_id' => 'gemini-it',
                'client_secret' => 'gemini-secret',
                'code_verifier' => $verifier,
            ]),
            ['Content-Type' => 'application/x-www-form-urlencoded']
        ), new Response());
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $body = json_decode((string) $ok->getBody(), true);
        self::assertIsString($body['access_token'] ?? null);

        // Basic auth
        $code3 = $server->issueCode(
            'gemini-it',
            1,
            McpConfig::GEMINI_ENTERPRISE_CALLBACK,
            ['iot.read'],
            self::BASE . '/mcp',
            $challenge
        );
        $basic = $oauth->token(self::request(
            'POST',
            '/oauth/token',
            http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code3,
                'redirect_uri' => McpConfig::GEMINI_ENTERPRISE_CALLBACK,
                'code_verifier' => $verifier,
            ]),
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode('gemini-it:gemini-secret'),
            ]
        ), new Response());
        self::assertSame(200, $basic->getStatusCode(), (string) $basic->getBody());
    }

    public function testAuthorizationServerMetadataAdvertisesConfidentialAuthMethods(): void
    {
        $meta = OAuthMetadata::authorizationServer($this->config, self::BASE);
        self::assertContains('none', $meta['token_endpoint_auth_methods_supported']);
        self::assertContains('client_secret_post', $meta['token_endpoint_auth_methods_supported']);
        self::assertContains('client_secret_basic', $meta['token_endpoint_auth_methods_supported']);
        self::assertTrue($meta['authorization_response_iss_parameter_supported']);
    }

    public function testBearerOnlyFromHeader(): void
    {
        self::assertSame('abc', McpController::bearerToken(self::request('POST', '/mcp', '', ['Authorization' => 'Bearer abc'])));
        self::assertNull(McpController::bearerToken(self::request('POST', '/mcp?access_token=abc')));
        $viaRedirect = (new ServerRequestFactory())->createServerRequest('POST', '/mcp', ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer xyz']);
        self::assertSame('xyz', McpController::bearerToken($viaRedirect), 'Apache FPM/CGI : REDIRECT_HTTP_AUTHORIZATION');
    }

    /**
     * @return array<string, string>
     */
    private function authorizeForm(string $password, string $username = 'lecteur'): array
    {
        $verifier = str_repeat('v', 50);
        $this->pdo->exec("INSERT OR IGNORE INTO mcp_oauth_clients (client_id, client_name, redirect_uris) VALUES ('mcp_c', 'C', '[\"https://claude.ai/api/mcp/auth_callback\"]')");
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['mcp_oauth_csrf'] = 'csrf-test';

        return [
            'client_id' => 'mcp_c', 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'response_type' => 'code',
            'state' => 's', 'scope' => 'iot.read', 'code_challenge' => \App\OAuth\Pkce::challengeFor($verifier),
            'code_challenge_method' => 'S256', '_mcp_csrf' => 'csrf-test', 'username' => $username,
            'password' => $password, 'decision' => 'allow',
        ];
    }

    public function testAccountLockoutAfterRepeatedFailures(): void
    {
        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->method('render')->willReturn('<html></html>');
        $controller = $this->oauthController($renderer);
        $post = fn (array $form) => $controller->authorizeSubmit(
            self::request('POST', '/oauth/authorize', http_build_query($form), ['Content-Type' => 'application/x-www-form-urlencoded'])
                ->withParsedBody($form),
            new Response()
        );
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $post($this->authorizeForm('mauvais'))->getStatusCode());
        }
        // Même avec le BON mot de passe, le compte reste bloqué pendant la fenêtre.
        self::assertSame(429, $post($this->authorizeForm(McpSqliteFixture::PASSWORD))->getStatusCode());
        // Un autre compte n'est pas affecté.
        self::assertSame(302, $post($this->authorizeForm(McpSqliteFixture::PASSWORD, 'admin'))->getStatusCode());
    }

    /**
     * Variantes que la collation `utf8mb4_unicode_ci` ramène au compte `admin` (vérifié sur
     * MariaDB) : chacune doit partager LE MÊME compteur d'échecs.
     *
     * @return list<string>
     */
    private static function adminVariants(): array
    {
        return ['ádmin', 'Ádmin', 'àdmîn', 'admin ', "a\u{0301}dmin", "ad\u{200B}min", 'ａｄｍｉｎ', ' ADMIN'];
    }

    public function testUsernameNormalizationFoldsCollationVariants(): void
    {
        foreach ([...self::adminVariants(), 'admin', 'ADMIN'] as $variant) {
            self::assertSame('admin', OAuthController::normalizeUsername($variant), json_encode($variant, JSON_THROW_ON_ERROR));
        }
        self::assertSame('lecteur', OAuthController::normalizeUsername('LÉCTEUR'));
        self::assertNotSame('admin', OAuthController::normalizeUsername('admin2'));
    }

    public function testAccountLockoutCannotBeBypassedWithAccentedVariants(): void
    {
        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->method('render')->willReturn('<html></html>');
        $controller = $this->oauthController($renderer);
        $post = fn (array $form) => $controller->authorizeSubmit(
            self::request('POST', '/oauth/authorize', http_build_query($form), ['Content-Type' => 'application/x-www-form-urlencoded'])
                ->withParsedBody($form),
            new Response()
        );
        // 5 échecs, chacun sous une variante différente du même compte.
        foreach (array_slice(self::adminVariants(), 0, 5) as $variant) {
            self::assertSame(401, $post($this->authorizeForm('mauvais', $variant))->getStatusCode(), $variant);
        }
        // Le compte est bloqué pour TOUTES les écritures, même avec le bon mot de passe.
        foreach (['admin', ...array_slice(self::adminVariants(), 5)] as $variant) {
            self::assertSame(429, $post($this->authorizeForm(McpSqliteFixture::PASSWORD, $variant))->getStatusCode(), $variant);
        }
        // Un autre compte n'est pas affecté.
        self::assertSame(302, $post($this->authorizeForm(McpSqliteFixture::PASSWORD, 'lecteur'))->getStatusCode());
    }

    public function testLockoutMessageIdenticalForUnknownAccount(): void
    {
        $bodies = [];
        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->method('render')->willReturnCallback(static function (string $template, array $context) use (&$bodies): string {
            $bodies[] = (string) ($context['error'] ?? '');

            return '<html></html>';
        });
        $controller = $this->oauthController($renderer);
        $post = fn (array $form) => $controller->authorizeSubmit(
            self::request('POST', '/oauth/authorize', http_build_query($form), ['Content-Type' => 'application/x-www-form-urlencoded'])
                ->withParsedBody($form),
            new Response()
        );
        foreach (['inconnu', 'Ínconnu', 'operateur', 'Opérateur'] as $i => $name) {
            for ($n = 0; $n < 3; $n++) {
                $post($this->authorizeForm('mauvais', $name));
            }
        }
        // Compte inconnu (clé nom normalisé) et compte existant (clés nom + id) : même blocage,
        // mêmes statuts, mêmes messages.
        self::assertSame(429, $post($this->authorizeForm('mauvais', 'inconnu'))->getStatusCode());
        $unknownLocked = end($bodies);
        self::assertSame(429, $post($this->authorizeForm(McpSqliteFixture::PASSWORD, 'operateur'))->getStatusCode());
        self::assertSame($unknownLocked, end($bodies));
        self::assertSame(1, count(array_unique(array_filter($bodies, static fn (string $b): bool => str_contains($b, 'Identifiants')))));
    }

    public function testOAuthPagesForbidFramingAndSecurityHeadersKeepIt(): void
    {
        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->method('render')->willReturn('<html></html>');
        $page = $this->oauthController($renderer)->authorizeForm(self::request('GET', '/oauth/authorize?client_id=inconnu'), new Response());
        $middleware = new \App\Middleware\SecurityHeadersMiddleware();
        $handler = new class ($page) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private \Psr\Http\Message\ResponseInterface $response)
            {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->response;
            }
        };
        $final = $middleware->process(self::request('GET', '/oauth/authorize'), $handler);
        self::assertSame('DENY', $final->getHeaderLine('X-Frame-Options'));
        self::assertStringContainsString("frame-ancestors 'none'", $final->getHeaderLine('Content-Security-Policy'));
    }
}
