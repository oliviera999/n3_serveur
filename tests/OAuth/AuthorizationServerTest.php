<?php

declare(strict_types=1);

namespace Tests\OAuth;

use App\Mcp\McpConfig;
use App\OAuth\AuthorizationServer;
use App\OAuth\ClientResolver;
use App\OAuth\HttpFetcher;
use App\OAuth\OAuthException;
use App\OAuth\Pkce;
use App\OAuth\RedirectUriValidator;
use App\Repository\McpOAuthRepository;
use App\Repository\UserRepository;
use App\Security\McpCredentialEpoch;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

/**
 * Serveur d'autorisation OAuth embarqué : PKCE, redirections (loopback sans port),
 * CIMD (client HTTP mocké), codes à usage unique, rotation des refresh tokens et
 * détection de réutilisation, révocation, scopes par rôle, audience.
 */
final class AuthorizationServerTest extends TestCase
{
    private const RESOURCE = 'https://iot.example.test/mcp';
    private const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    private PDO $pdo;
    private McpOAuthRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = McpSqliteFixture::pdo();
        $this->repo = new McpOAuthRepository($this->pdo);
    }

    private function server(?McpConfig $config = null): AuthorizationServer
    {
        return new AuthorizationServer($this->repo, new UserRepository($this->pdo), $config ?? McpSqliteFixture::config());
    }

    /**
     * @return array{0: string, 1: string} [verifier, challenge]
     */
    private static function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');

        return [$verifier, Pkce::challengeFor($verifier)];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} [tokens, verifier]
     */
    private function fullFlow(int $userId = 1, string $scope = 'iot.read offline_access', ?AuthorizationServer $server = null): array
    {
        $server ??= $this->server();
        [$verifier, $challenge] = self::pkce();
        $code = $server->issueCode('client-a', $userId, self::CLAUDE_CALLBACK, $server->grantableScopes($scope, 'reader'), self::RESOURCE, $challenge);

        return [$server->exchangeCode($code, 'client-a', self::CLAUDE_CALLBACK, $verifier, self::RESOURCE, self::RESOURCE), $verifier];
    }

    // ── PKCE ──────────────────────────────────────────────────────────

    public function testPkceS256MatchesRfc7636AppendixB(): void
    {
        // Vecteur de test officiel RFC 7636 annexe B.
        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
        self::assertTrue(Pkce::verify('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        self::assertFalse(Pkce::verify('court', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        self::assertFalse(Pkce::isValidChallenge('plain-challenge'));
    }

    // ── Redirections ──────────────────────────────────────────────────

    public function testRedirectPolicyExactClaudeAndPortAgnosticLoopback(): void
    {
        $v = new RedirectUriValidator(McpSqliteFixture::config());
        self::assertTrue($v->isAllowedByPolicy(self::CLAUDE_CALLBACK));
        self::assertFalse($v->isAllowedByPolicy(self::CLAUDE_CALLBACK . '/'));
        self::assertFalse($v->isAllowedByPolicy('https://claude.ai/api/mcp/auth_callback?x=1'));
        self::assertFalse($v->isAllowedByPolicy('https://evil.example/api/mcp/auth_callback'));
        self::assertFalse($v->isAllowedByPolicy('https://claude.ai.evil.example/api/mcp/auth_callback'));
        self::assertTrue($v->isAllowedByPolicy('http://localhost:3118/callback'));
        self::assertTrue($v->isAllowedByPolicy('http://127.0.0.1:61999/callback'));
        self::assertFalse($v->isAllowedByPolicy('http://evil.example/callback'));
        self::assertFalse($v->isAllowedByPolicy('http://localhost:3118/callback#frag'));
        self::assertFalse($v->isAllowedByPolicy('http://user@localhost/callback'));

        // Comparaison au document client : port ignoré pour le loopback uniquement.
        self::assertTrue(RedirectUriValidator::matches('http://localhost/callback', 'http://localhost:3118/callback'));
        self::assertTrue(RedirectUriValidator::matches('http://127.0.0.1/callback', 'http://127.0.0.1:9/callback'));
        self::assertFalse(RedirectUriValidator::matches('http://localhost/callback', 'http://127.0.0.1:3118/callback'));
        self::assertFalse(RedirectUriValidator::matches('http://localhost/callback', 'http://localhost:3118/other'));
        self::assertFalse(RedirectUriValidator::matches(self::CLAUDE_CALLBACK, 'https://claude.ai:8443/api/mcp/auth_callback'));
    }

    public function testExtraRedirectUrisFromEnv(): void
    {
        $v = new RedirectUriValidator(McpSqliteFixture::config(['MCP_EXTRA_REDIRECT_URIS' => 'https://inspector.example/cb']));
        self::assertTrue($v->isAllowedByPolicy('https://inspector.example/cb'));
    }

    public function testBuiltinRedirectsForCursorChatgptGeminiAndCursorLegacy(): void
    {
        $v = new RedirectUriValidator(McpSqliteFixture::config());
        self::assertTrue($v->isAllowedByPolicy(McpConfig::CURSOR_WEB_CALLBACK));
        self::assertTrue($v->isAllowedByPolicy(McpConfig::CHATGPT_CALLBACK));
        self::assertTrue($v->isAllowedByPolicy(McpConfig::GEMINI_ENTERPRISE_CALLBACK));
        self::assertTrue($v->isAllowedByPolicy(McpConfig::CURSOR_LEGACY_CALLBACK));
        self::assertTrue($v->isAllowedByPolicy('http://localhost:8787/callback'), 'Cursor desktop loopback');
        self::assertFalse($v->isAllowedByPolicy('cursor://evil.app/oauth/callback'));
        self::assertFalse($v->isAllowedByPolicy('cursor://anysphere.cursor-mcp/oauth/callback/extra'));
    }

    public function testDefaultCimdHostsAndOriginsIncludeMultiClients(): void
    {
        $config = McpSqliteFixture::config();
        foreach (['claude.ai', 'claude.com', 'chatgpt.com', 'cursor.com', 'www.cursor.com'] as $host) {
            self::assertContains($host, $config->cimdAllowedHosts());
        }
        $origins = $config->allowedOrigins('https://iot.example.test');
        foreach (['https://claude.ai', 'https://chatgpt.com', 'https://cursor.com', 'https://www.cursor.com', 'https://iot.example.test'] as $origin) {
            self::assertContains($origin, $origins);
        }
    }

    public function testConfidentialClientResolutionAndSecretVerification(): void
    {
        $config = McpSqliteFixture::config([
            'MCP_OAUTH_CONFIDENTIAL_CLIENT_ID' => 'gemini-static-id',
            'MCP_OAUTH_CONFIDENTIAL_CLIENT_SECRET' => 'super-secret-value',
        ]);
        self::assertTrue($config->hasConfidentialClient());
        $client = $config->confidentialClient();
        self::assertNotNull($client);
        self::assertSame('confidential', $client['type']);
        self::assertSame([McpConfig::GEMINI_ENTERPRISE_CALLBACK], $client['redirect_uris']);

        $resolver = new ClientResolver($this->repo, self::fetcher([]), $config);
        $resolved = $resolver->resolve('gemini-static-id');
        self::assertSame('confidential', $resolved['type']);
        self::assertTrue($config->verifyConfidentialClientSecret('gemini-static-id', 'super-secret-value'));
        self::assertFalse($config->verifyConfidentialClientSecret('gemini-static-id', 'wrong'));
        self::assertFalse($config->verifyConfidentialClientSecret('other-id', 'super-secret-value'));

        $this->expectException(OAuthException::class);
        $resolver->resolve('unknown-dcr-client');
    }

    public function testConfidentialClientDisabledWhenSecretMissing(): void
    {
        $config = McpSqliteFixture::config([
            'MCP_OAUTH_CONFIDENTIAL_CLIENT_ID' => 'gemini-static-id',
            'MCP_OAUTH_CONFIDENTIAL_CLIENT_SECRET' => '',
        ]);
        self::assertFalse($config->hasConfidentialClient());
        self::assertNull($config->confidentialClient());
    }

    // ── CIMD ──────────────────────────────────────────────────────────

    private static function fetcher(array $document, int $status = 200, ?\Throwable $error = null): HttpFetcher
    {
        return new class ($document, $status, $error) implements HttpFetcher {
            public int $calls = 0;

            public function __construct(private array $document, private int $status, private ?\Throwable $error)
            {
            }

            public function get(string $url, int $timeoutSeconds, int $maxBytes, ?string $pinnedIp = null): array
            {
                $this->calls++;
                if ($timeoutSeconds > 3) {
                    throw new \LogicException('délai CIMD trop long');
                }
                if ($this->error !== null) {
                    throw $this->error;
                }

                return ['status' => $this->status, 'body' => (string) json_encode($this->document), 'content_type' => 'application/json'];
            }
        };
    }

    public function testCimdDocumentIsFetchedValidatedAndCached(): void
    {
        $url = 'https://claude.ai/oauth/claude-code-client-metadata';
        $fetcher = self::fetcher([
            'client_id' => $url,
            'client_name' => 'Claude Code',
            'redirect_uris' => ['http://localhost/callback', 'http://127.0.0.1/callback'],
            'token_endpoint_auth_method' => 'none',
        ]);
        $resolver = new ClientResolver($this->repo, $fetcher, McpSqliteFixture::config());
        $client = $resolver->resolve($url);
        self::assertSame('cimd', $client['type']);
        self::assertSame('claude.ai', $client['display_host']);
        self::assertTrue((new RedirectUriValidator(McpSqliteFixture::config()))->matchesAny($client['redirect_uris'], 'http://localhost:4567/callback'));
        $resolver->resolve($url);
        self::assertSame(1, $fetcher->calls, 'document mis en cache');
    }

    public function testCimdRejectsMismatchedClientIdForeignHostAndConfidentialClients(): void
    {
        $url = 'https://claude.ai/oauth/meta';
        $cases = [
            [self::fetcher(['client_id' => 'https://claude.ai/autre', 'redirect_uris' => [self::CLAUDE_CALLBACK]]), $url],
            [self::fetcher(['client_id' => $url, 'redirect_uris' => []]), $url],
            [self::fetcher(['client_id' => $url, 'redirect_uris' => [self::CLAUDE_CALLBACK], 'token_endpoint_auth_method' => 'client_secret_basic']), $url],
            [self::fetcher([], 404), $url],
            [self::fetcher([], 200, new \RuntimeException('timeout')), $url],
            [self::fetcher(['client_id' => 'https://evil.example/meta', 'redirect_uris' => [self::CLAUDE_CALLBACK]]), 'https://evil.example/meta'],
            [self::fetcher([]), 'http://claude.ai/oauth/meta'],
        ];
        foreach ($cases as $i => [$fetcher, $clientId]) {
            try {
                (new ClientResolver(new McpOAuthRepository(McpSqliteFixture::pdo()), $fetcher, McpSqliteFixture::config()))->resolve($clientId);
                self::fail('cas ' . $i . ' aurait dû être refusé');
            } catch (OAuthException $e) {
                self::assertSame('invalid_client', $e->error, 'cas ' . $i);
            }
        }
    }

    public function testDcrClientResolution(): void
    {
        $this->repo->createClient('mcp_abc', 'Inspecteur', [self::CLAUDE_CALLBACK], null);
        $resolver = new ClientResolver($this->repo, self::fetcher([]), McpSqliteFixture::config());
        self::assertSame([self::CLAUDE_CALLBACK], $resolver->resolve('mcp_abc')['redirect_uris']);
        $this->expectException(OAuthException::class);
        $resolver->resolve('mcp_inconnu');
    }

    // ── Codes et jetons ───────────────────────────────────────────────

    public function testAuthorizationCodeFlowIssuesHashedTokens(): void
    {
        [$tokens] = $this->fullFlow();
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame(3600, $tokens['expires_in']);
        self::assertSame('iot.read offline_access', $tokens['scope']);
        self::assertArrayHasKey('refresh_token', $tokens);

        $stored = $this->pdo->query('SELECT token_hash FROM mcp_oauth_tokens')->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains($tokens['access_token'], $stored, 'jamais stocké en clair');
        self::assertContains(hash('sha256', $tokens['access_token']), $stored);
        $codes = $this->pdo->query('SELECT code_hash FROM mcp_oauth_codes')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(64, strlen($codes[0]));

        $valid = $this->server()->validateAccessToken($tokens['access_token'], self::RESOURCE);
        self::assertNotNull($valid);
        self::assertSame('lecteur', $valid['user']->username);
        self::assertSame(['iot.read', 'offline_access'], $valid['scopes']);
        self::assertNull($this->server()->validateAccessToken($tokens['access_token'], 'https://autre.example/mcp'), 'audience vérifiée');
    }

    public function testNoRefreshTokenWithoutOfflineAccess(): void
    {
        [$tokens] = $this->fullFlow(1, 'iot.read');
        self::assertArrayNotHasKey('refresh_token', $tokens);
    }

    public function testCodeIsSingleUseAndReplayRevokesIssuedTokens(): void
    {
        $server = $this->server();
        [$verifier, $challenge] = self::pkce();
        $code = $server->issueCode('client-a', 1, self::CLAUDE_CALLBACK, ['iot.read', 'offline_access'], null, $challenge);
        $tokens = $server->exchangeCode($code, 'client-a', self::CLAUDE_CALLBACK, $verifier, null, self::RESOURCE);
        try {
            $server->exchangeCode($code, 'client-a', self::CLAUDE_CALLBACK, $verifier, null, self::RESOURCE);
            self::fail('rejeu accepté');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
        }
        self::assertNull($server->validateAccessToken($tokens['access_token'], self::RESOURCE), 'jetons du code rejoué révoqués');
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function badExchangeProvider(): iterable
    {
        yield 'autre client' => ['client-b', self::CLAUDE_CALLBACK, 'ok'];
        yield 'autre redirect_uri' => ['client-a', 'http://localhost:1/callback', 'ok'];
        yield 'mauvais verifier' => ['client-a', self::CLAUDE_CALLBACK, 'bad'];
    }

    /**
     * @dataProvider badExchangeProvider
     */
    public function testExchangeRejectsMismatches(string $clientId, string $redirect, string $verifierMode): void
    {
        $server = $this->server();
        [$verifier, $challenge] = self::pkce();
        $code = $server->issueCode('client-a', 1, self::CLAUDE_CALLBACK, ['iot.read'], null, $challenge);
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessageMatches('/.+/');
        try {
            $server->exchangeCode($code, $clientId, $redirect, $verifierMode === 'ok' ? $verifier : str_repeat('a', 50), null, self::RESOURCE);
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
            throw $e;
        }
    }

    public function testExpiredCodeRejected(): void
    {
        $server = $this->server();
        [$verifier, $challenge] = self::pkce();
        $code = $server->issueCode('client-a', 1, self::CLAUDE_CALLBACK, ['iot.read'], null, $challenge);
        $this->pdo->exec("UPDATE mcp_oauth_codes SET expires_at = '2000-01-01 00:00:00'");
        try {
            $server->exchangeCode($code, 'client-a', self::CLAUDE_CALLBACK, $verifier, null, self::RESOURCE);
            self::fail('code expiré accepté');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
        }
    }

    public function testResourceParameterValidated(): void
    {
        $server = $this->server();
        self::assertSame(self::RESOURCE, $server->validateResource(self::RESOURCE . '/', self::RESOURCE));
        self::assertNull($server->validateResource(null, self::RESOURCE));
        $this->expectException(OAuthException::class);
        $server->validateResource('https://autre.example/mcp', self::RESOURCE);
    }

    public function testRefreshRotationAndReuseRevokesFamily(): void
    {
        $server = $this->server();
        [$first] = $this->fullFlow(1, 'iot.read offline_access', $server);
        $second = $server->refresh($first['refresh_token'], 'client-a', null, self::RESOURCE);
        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertNotNull($server->validateAccessToken($second['access_token'], self::RESOURCE));

        try {
            $server->refresh($first['refresh_token'], 'client-a', null, self::RESOURCE);
            self::fail('réutilisation acceptée');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
        }
        // Toute la famille est révoquée : le refresh le plus récent et les accès aussi.
        self::assertNull($server->validateAccessToken($second['access_token'], self::RESOURCE));
        $this->expectException(OAuthException::class);
        $server->refresh($second['refresh_token'], 'client-a', null, self::RESOURCE);
    }

    public function testRefreshCannotWidenScopesNorBeUsedByAnotherClient(): void
    {
        $server = $this->server();
        [$tokens] = $this->fullFlow();
        try {
            $server->refresh($tokens['refresh_token'], 'client-b', null, self::RESOURCE);
            self::fail('autre client accepté');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
        }
        $this->expectException(OAuthException::class);
        $server->refresh($tokens['refresh_token'], 'client-a', 'iot.read iot.control', self::RESOURCE);
    }

    public function testDeactivatedUserLosesAccessAndRefresh(): void
    {
        $server = $this->server();
        [$tokens] = $this->fullFlow(2);
        $this->pdo->exec('UPDATE n3_users SET is_active = 0 WHERE id = 2');
        self::assertNull($server->validateAccessToken($tokens['access_token'], self::RESOURCE));
        $this->expectException(OAuthException::class);
        $server->refresh($tokens['refresh_token'], 'client-a', null, self::RESOURCE);
    }

    public function testPasswordChangeInvalidatesAccessAndRefreshTokens(): void
    {
        $server = $this->server();
        [$tokens] = $this->fullFlow(1);
        self::assertNotNull($server->validateAccessToken($tokens['access_token'], self::RESOURCE));
        (new UserRepository($this->pdo))->updatePassword(1, password_hash('nouveau-mot-de-passe', PASSWORD_BCRYPT, ['cost' => 4]));
        self::assertNull($server->validateAccessToken($tokens['access_token'], self::RESOURCE));
        $this->expectException(OAuthException::class);
        $server->refresh($tokens['refresh_token'], 'client-a', null, self::RESOURCE);
    }

    public function testPasswordChangedOutsideTheAppAlsoInvalidatesTokens(): void
    {
        // Changement par SQL direct (phpMyAdmin, outil) : l'empreinte d'identifiants suffit.
        $server = $this->server();
        [$tokens] = $this->fullFlow(1);
        $this->pdo->exec("UPDATE n3_users SET password_hash = 'autre-hash' WHERE id = 1");
        self::assertNull($server->validateAccessToken($tokens['access_token'], self::RESOURCE));
        try {
            $server->refresh($tokens['refresh_token'], 'client-a', null, self::RESOURCE);
            self::fail('refresh accepté après changement de mot de passe');
        } catch (OAuthException $e) {
            self::assertSame('invalid_grant', $e->error);
        }
    }

    public function testCredentialEpochKeyIsDerivedFromServerSecret(): void
    {
        $saved = [$_ENV['MCP_CREDENTIAL_SECRET'] ?? null, $_ENV['API_SIG_SECRET'] ?? null, getenv('MCP_CREDENTIAL_SECRET'), getenv('API_SIG_SECRET')];
        try {
            putenv('MCP_CREDENTIAL_SECRET');
            putenv('API_SIG_SECRET');
            unset($_ENV['MCP_CREDENTIAL_SECRET'], $_ENV['API_SIG_SECRET']);
            self::assertTrue(McpCredentialEpoch::isFallback());
            $fallback = McpCredentialEpoch::compute('hash');

            $_ENV['API_SIG_SECRET'] = 'secret-esp32';
            self::assertFalse(McpCredentialEpoch::isFallback());
            $fromSig = McpCredentialEpoch::compute('hash');
            self::assertSame(hash_hmac('sha256', 'mcp-credential-epoch', 'secret-esp32'), McpCredentialEpoch::key());
            self::assertNotSame($fallback, $fromSig);

            // Secret dédié prioritaire sur API_SIG_SECRET.
            $_ENV['MCP_CREDENTIAL_SECRET'] = 'secret-mcp';
            self::assertSame(hash_hmac('sha256', 'mcp-credential-epoch', 'secret-mcp'), McpCredentialEpoch::key());
            self::assertNotSame($fromSig, McpCredentialEpoch::compute('hash'));

            // Tourner le secret invalide les jetons existants (ré-authentification requise).
            $server = $this->server();
            [$tokens] = $this->fullFlow(1);
            self::assertNotNull($server->validateAccessToken($tokens['access_token'], self::RESOURCE));
            $_ENV['MCP_CREDENTIAL_SECRET'] = 'secret-mcp-tourne';
            self::assertNull($server->validateAccessToken($tokens['access_token'], self::RESOURCE));
            // Une nouvelle connexion refonctionne immédiatement.
            [$again] = $this->fullFlow(1);
            self::assertNotNull($server->validateAccessToken($again['access_token'], self::RESOURCE));
        } finally {
            foreach (['MCP_CREDENTIAL_SECRET' => [0, 2], 'API_SIG_SECRET' => [1, 3]] as $name => [$envIndex, $getenvIndex]) {
                if ($saved[$envIndex] === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $saved[$envIndex];
                }
                putenv($saved[$getenvIndex] === false ? $name : $name . '=' . $saved[$getenvIndex]);
            }
        }
    }

    public function testRoleChangeOrDeactivationThroughRepositoryRevokesTokens(): void
    {
        $users = new UserRepository($this->pdo);
        [$tokens] = $this->fullFlow(2);
        $users->update(2, null, null, 'operator', true); // inchangé : rien n'est révoqué
        self::assertNotNull($this->server()->validateAccessToken($tokens['access_token'], self::RESOURCE));
        $users->update(2, null, null, 'reader', true);
        self::assertNull($this->server()->validateAccessToken($tokens['access_token'], self::RESOURCE));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mcp_oauth_tokens WHERE user_id = 2 AND revoked_at IS NULL')->fetchColumn());

        [$again] = $this->fullFlow(2);
        $users->update(2, null, null, 'reader', false);
        self::assertNull($this->server()->validateAccessToken($again['access_token'], self::RESOURCE));
    }

    public function testTokenWithoutResourceIsRejected(): void
    {
        $token = AuthorizationServer::randomToken();
        $this->repo->storeToken(AuthorizationServer::hash($token), 'access', 'f', 'client-a', 1, 'iot.read', null, 3600, (new UserRepository($this->pdo))->credentialEpoch(1));
        self::assertNull($this->server()->validateAccessToken($token, self::RESOURCE));
    }

    public function testCimdPublicIpChecks(): void
    {
        $refused = [
            '10.0.0.1', '127.0.0.1', '169.254.1.1', '172.16.5.4', '192.168.1.1', '100.64.0.1', '0.0.0.0', '255.255.255.255', '224.0.0.1',
            'fd12::1', 'fe80::1', '::1', '::', 'ff02::1', '2001:db8::1', '2002:a00:1::1',
            // IPv4 mappée : forme pointée et forme hexadécimale, jugées sur l'IPv4 extraite.
            '::ffff:10.0.0.1', '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:a00:1', '::ffff:7f00:1',
            '64:ff9b::a00:1', '64:ff9b::127.0.0.1',
            'pas-une-ip', '',
        ];
        foreach ($refused as $ip) {
            self::assertFalse(ClientResolver::isPublicIp($ip), $ip);
        }
        foreach (['8.8.8.8', '1.1.1.1', '2606:4700::1111', '::ffff:8.8.8.8', '::ffff:808:808', '::FFFF:8.8.4.4', '64:ff9b::808:808'] as $ip) {
            self::assertTrue(ClientResolver::isPublicIp($ip), $ip);
        }
        // Un hôte dont UNE adresse est privée est refusé (rebinding multi-enregistrements).
        self::assertNull(ClientResolver::resolvePublicIp('meta.example', static fn (): array => ['8.8.8.8', 'fd00::1']));
        self::assertSame('8.8.8.8', ClientResolver::resolvePublicIp('meta.example', static fn (): array => ['8.8.8.8']));
        self::assertNull(ClientResolver::resolvePublicIp('localhost'));
    }

    public function testRevokeRefreshRevokesWholeFamily(): void
    {
        $server = $this->server();
        [$tokens] = $this->fullFlow();
        $server->revoke($tokens['refresh_token'], 'client-a');
        self::assertNull($server->validateAccessToken($tokens['access_token'], self::RESOURCE));
        $server->revoke('jeton-inconnu', null); // RFC 7009 : silencieux
        $this->addToAssertionCount(1);
    }

    public function testScopesDependOnRoleAndControlFlag(): void
    {
        $off = $this->server();
        self::assertSame(['iot.read', 'offline_access'], $off->grantableScopes('', 'admin'));
        self::assertSame(['iot.read'], $off->grantableScopes('iot.control', 'admin'), 'pilotage désactivé : jamais accordé');

        $on = $this->server(McpSqliteFixture::config(['MCP_CONTROL_ENABLED' => 'true']));
        self::assertSame(['iot.read', 'iot.control'], $on->grantableScopes('iot.read iot.control', 'operator'));
        self::assertSame(['iot.read'], $on->grantableScopes('iot.read iot.control', 'reader'), 'reader : jamais iot.control');
        self::assertSame(['iot.read'], $on->grantableScopes('openid profile', 'admin'), 'scopes inconnus ignorés');
    }

    public function testDowngradedUserLosesControlScopeImmediately(): void
    {
        $config = McpSqliteFixture::config(['MCP_CONTROL_ENABLED' => 'true']);
        $server = $this->server($config);
        [$verifier, $challenge] = self::pkce();
        $code = $server->issueCode('client-a', 2, self::CLAUDE_CALLBACK, ['iot.read', 'iot.control'], null, $challenge);
        $tokens = $server->exchangeCode($code, 'client-a', self::CLAUDE_CALLBACK, $verifier, null, self::RESOURCE);
        self::assertContains('iot.control', $server->validateAccessToken($tokens['access_token'], self::RESOURCE)['scopes']);
        $this->pdo->exec("UPDATE n3_users SET role = 'reader' WHERE id = 2");
        self::assertSame(['iot.read'], $server->validateAccessToken($tokens['access_token'], self::RESOURCE)['scopes']);
    }

    public function testPurgeRemovesExpiredRows(): void
    {
        $this->fullFlow();
        $this->pdo->exec("UPDATE mcp_oauth_tokens SET expires_at = '2000-01-01 00:00:00'");
        $this->pdo->exec("UPDATE mcp_oauth_codes SET expires_at = '2000-01-01 00:00:00'");
        $result = $this->repo->purgeExpired();
        self::assertSame(2, $result['tokens']);
        self::assertSame(1, $result['codes']);
    }

    public function testExpiredAccessTokenRejected(): void
    {
        [$tokens] = $this->fullFlow();
        $this->pdo->exec("UPDATE mcp_oauth_tokens SET expires_at = '2000-01-01 00:00:00' WHERE token_type = 'access'");
        self::assertNull($this->server()->validateAccessToken($tokens['access_token'], self::RESOURCE));
    }

    public function testConfigDefaults(): void
    {
        $config = new McpConfig([]);
        self::assertFalse($config->isEnabled());
        self::assertFalse($config->isControlEnabled());
        self::assertSame(['prod', 's3'], $config->allowedEnvs());
        self::assertSame(['ffp3:test', 'ffp3:test3', 'ffp3:s3test', 'n3pp:n3pp_test', 'msp1:msp_test'], $config->controlAllowedTargets());
        self::assertFalse($config->isProductionControlAllowed());
        self::assertSame(['ffp3:test'], (new McpConfig(['MCP_CONTROL_ALLOWED_TARGETS' => 'ffp3:test, inconnu:prod, ffp3:nope, energie:energie_test']))->controlAllowedTargets());
        self::assertSame(['prod'], (new McpConfig(['MCP_ALLOWED_ENVS' => 'inconnu']))->allowedEnvs());
        self::assertSame(3600, $config->accessTokenTtl());
        self::assertSame(60, $config->authCodeTtl());
        self::assertSame(30 * 86400, $config->refreshTokenTtl());
    }
}
