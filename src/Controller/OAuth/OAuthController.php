<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Controller\McpController;
use App\Domain\User;
use App\Mcp\McpConfig;
use App\OAuth\AuthorizationServer;
use App\OAuth\ClientResolver;
use App\OAuth\OAuthException;
use App\OAuth\OAuthMetadata;
use App\OAuth\Pkce;
use App\OAuth\RedirectUriValidator;
use App\Repository\McpOAuthRepository;
use App\Repository\UserRepository;
use App\Security\RateLimiter;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\ClientIpResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Endpoints du serveur d'autorisation OAuth embarqué (connecteur MCP Claude).
 *
 *  - GET  /.well-known/oauth-protected-resource[/mcp]   RFC 9728
 *  - GET  /.well-known/oauth-authorization-server        RFC 8414
 *  - POST /oauth/register                                RFC 7591 (DCR, clients publics)
 *  - GET  /oauth/authorize                               page de connexion + consentement
 *  - POST /oauth/authorize                               validation (CSRF, rate limit)
 *  - POST /oauth/token                                   authorization_code + PKCE, refresh_token (rotation)
 *  - POST /oauth/revoke                                  RFC 7009
 *
 * La connexion du formulaire vérifie le mot de passe des comptes `n3_users` ACTIFS
 * ({@see UserRepository::verifyPassword()}, la même vérification que la connexion web,
 * anti-énumération comprise) ; le compte `.env` de secours n'est pas accepté (pas d'id
 * stable à lier au jeton). Aucune session web n'est ouverte : la page est autonome.
 */
final class OAuthController
{
    private const CSRF_SESSION_KEY = 'mcp_oauth_csrf';
    private const LOGIN_MAX_ATTEMPTS = 10;
    private const LOGIN_WINDOW_SECONDS = 600;

    public function __construct(
        private McpConfig $config,
        private AuthorizationServer $server,
        private ClientResolver $clients,
        private RedirectUriValidator $redirects,
        private McpOAuthRepository $repo,
        private UserRepository $users,
        private RateLimiter $limiter,
        private TemplateRenderer $renderer,
        private LogService $logger,
    ) {
    }

    // ── Découverte ────────────────────────────────────────────────────

    public function protectedResource(Request $request, Response $response): Response
    {
        $base = OAuthMetadata::baseUrl($this->config, $request);

        return self::cors(McpController::json($response, 200, OAuthMetadata::protectedResource($this->config, $base)))
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function authorizationServer(Request $request, Response $response): Response
    {
        $base = OAuthMetadata::baseUrl($this->config, $request);

        return self::cors(McpController::json($response, 200, OAuthMetadata::authorizationServer($this->config, $base)))
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    // ── DCR (RFC 7591) ────────────────────────────────────────────────

    public function register(Request $request, Response $response): Response
    {
        if (!$this->config->isDcrEnabled()) {
            return McpController::json($response, 404, ['error' => 'not_found']);
        }
        $data = json_decode((string) $request->getBody(), true);
        if (!is_array($data) || array_is_list($data)) {
            return $this->oauthError($response, new OAuthException('invalid_client_metadata', 'Corps JSON attendu (RFC 7591).'));
        }
        $uris = $data['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > 5) {
            return $this->oauthError($response, new OAuthException('invalid_redirect_uri', 'redirect_uris requis (1 à 5 URI).'));
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !$this->redirects->isAllowedByPolicy($uri)) {
                return $this->oauthError($response, new OAuthException(
                    'invalid_redirect_uri',
                    'redirect_uri non autorisée : seules https://claude.ai/api/mcp/auth_callback, les URI loopback '
                    . 'http://localhost|127.0.0.1/… (port libre) et celles de MCP_EXTRA_REDIRECT_URIS sont acceptées.'
                ));
            }
        }
        $method = $data['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            return $this->oauthError($response, new OAuthException('invalid_client_metadata', 'Seuls les clients publics (token_endpoint_auth_method=none) sont acceptés.'));
        }
        foreach ((array) ($data['grant_types'] ?? ['authorization_code']) as $grant) {
            if (!in_array($grant, ['authorization_code', 'refresh_token'], true)) {
                return $this->oauthError($response, new OAuthException('invalid_client_metadata', 'grant_type non supporté : ' . (string) $grant));
            }
        }
        $name = is_string($data['client_name'] ?? null) ? mb_substr(strip_tags($data['client_name']), 0, 100) : null;
        $software = is_string($data['software_id'] ?? null) ? mb_substr($data['software_id'], 0, 191) : null;
        $clientId = 'mcp_' . bin2hex(random_bytes(16));
        /** @var list<string> $uris */
        $this->repo->createClient($clientId, $name, array_values($uris), $software);
        $this->logger->info('[mcp-oauth] client DCR enregistré {client} ({name})', ['client' => $clientId, 'name' => (string) $name]);

        return McpController::json($response, 201, [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    // ── Autorisation ──────────────────────────────────────────────────

    public function authorizeForm(Request $request, Response $response): Response
    {
        return $this->handleAuthorize($request, $response, $request->getQueryParams(), false);
    }

    public function authorizeSubmit(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            parse_str((string) $request->getAttribute('raw_post_body', (string) $request->getBody()), $body);
        }

        return $this->handleAuthorize($request, $response, $body, true);
    }

    /**
     * @param array<mixed> $params
     */
    private function handleAuthorize(Request $request, Response $response, array $params, bool $submitted): Response
    {
        $base = OAuthMetadata::baseUrl($this->config, $request);
        $str = static fn (string $key): string => is_string($params[$key] ?? null) ? trim($params[$key]) : '';

        // 1) Client et redirection : en cas d'échec, NE PAS rediriger (RFC 6749 §4.1.2.1).
        try {
            $client = $this->clients->resolve($str('client_id'));
        } catch (OAuthException $e) {
            return $this->errorPage($response, 'Application inconnue', $e->getMessage());
        }
        $redirectUri = $str('redirect_uri');
        if ($redirectUri === '' || !$this->redirects->isAllowedByPolicy($redirectUri)
            || !$this->redirects->matchesAny($client['redirect_uris'], $redirectUri)) {
            return $this->errorPage($response, 'Redirection refusée', 'L\'adresse de retour demandée n\'est pas enregistrée pour cette application.');
        }
        $state = $str('state');

        // 2) Paramètres de la demande : erreurs renvoyées au client par redirection.
        try {
            if ($str('response_type') !== 'code') {
                throw new OAuthException('unsupported_response_type', 'Seul response_type=code est supporté.');
            }
            if ($str('code_challenge_method') !== 'S256' || !Pkce::isValidChallenge($str('code_challenge'))) {
                throw new OAuthException('invalid_request', 'PKCE obligatoire : code_challenge S256 requis.');
            }
            if (strlen($state) > 500) {
                throw new OAuthException('invalid_request', 'Paramètre state trop long.');
            }
            $resource = $this->server->validateResource($str('resource') !== '' ? $str('resource') : null, OAuthMetadata::resourceUrl($base));
        } catch (OAuthException $e) {
            return $this->redirectError($response, $redirectUri, $e, $state, $base);
        }

        $requestedScope = $str('scope');
        $context = [
            'page_title' => 'Autoriser l\'accès — n³ IoT',
            'client_label' => $client['type'] === 'cimd' ? (string) $client['display_host'] : ($client['client_name'] ?? 'Application MCP'),
            'client_name' => $client['client_name'],
            'client_type' => $client['type'],
            'redirect_host' => (string) parse_url($redirectUri, PHP_URL_HOST),
            'redirect_uri' => $redirectUri,
            'is_loopback' => RedirectUriValidator::isLoopback($redirectUri),
            'requested_scopes' => $this->describeScopes($requestedScope),
            'control_enabled' => $this->config->isControlEnabled(),
            'hidden' => [
                'client_id' => $client['client_id'],
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'state' => $state,
                'scope' => $requestedScope,
                'code_challenge' => $str('code_challenge'),
                'code_challenge_method' => 'S256',
                'resource' => $resource ?? '',
            ],
            'error' => null,
            'username' => '',
        ];

        if (!$submitted) {
            return $this->renderConsent($response, $context);
        }

        if (!$this->checkCsrf($str('_mcp_csrf'))) {
            $context['error'] = 'Formulaire expiré : merci de réessayer.';

            return $this->renderConsent($response->withStatus(400), $context);
        }
        if ($str('decision') === 'deny') {
            return $this->redirectError($response, $redirectUri, new OAuthException('access_denied', 'Accès refusé par l\'utilisateur.'), $state, $base);
        }

        $ipKey = 'mcp_oauth_login:' . ClientIpResolver::resolve($request);
        if ($this->limiter->hit($ipKey, self::LOGIN_WINDOW_SECONDS) > self::LOGIN_MAX_ATTEMPTS) {
            $context['error'] = 'Trop de tentatives. Réessayez dans quelques minutes.';

            return $this->renderConsent($response->withStatus(429), $context);
        }

        $username = $str('username');
        $user = $username !== '' ? $this->users->verifyPassword($username, is_string($params['password'] ?? null) ? $params['password'] : '') : null;
        if ($user === null) {
            $this->logger->warning('[mcp-oauth] échec de connexion pour {user}', ['user' => mb_substr($username, 0, 64)]);
            $context['error'] = 'Identifiants incorrects ou compte désactivé.';
            $context['username'] = $username;

            return $this->renderConsent($response->withStatus(401), $context);
        }
        $this->limiter->clear($ipKey);

        $scopes = $this->server->grantableScopes($requestedScope, $user->role);
        $code = $this->server->issueCode($client['client_id'], $user->id, $redirectUri, $scopes, $resource, $str('code_challenge'));
        $this->logger->info('[mcp-oauth] consentement accordé user={user} client={client} scopes={scopes}', [
            'user' => $user->username,
            'client' => mb_substr($client['client_id'], 0, 120),
            'scopes' => implode(' ', $scopes),
        ]);

        return self::redirect($response, $redirectUri, array_filter([
            'code' => $code,
            'state' => $state !== '' ? $state : null,
            'iss' => $base,
        ], static fn ($v): bool => $v !== null));
    }

    // ── Jetons ────────────────────────────────────────────────────────

    public function token(Request $request, Response $response): Response
    {
        $params = self::formParams($request);
        $base = OAuthMetadata::baseUrl($this->config, $request);
        $resourceUrl = OAuthMetadata::resourceUrl($base);
        $get = static fn (string $k): string => is_string($params[$k] ?? null) ? trim($params[$k]) : '';

        try {
            $grant = $get('grant_type');
            $clientId = $get('client_id');
            if ($clientId !== '' && !ClientResolver::isUrlClientId($clientId) && $this->repo->findClient($clientId) === null) {
                throw new OAuthException('invalid_client', 'Client inconnu.', 401);
            }
            $result = match ($grant) {
                'authorization_code' => $this->server->exchangeCode(
                    $get('code'),
                    $clientId,
                    $get('redirect_uri'),
                    $get('code_verifier'),
                    $get('resource') !== '' ? $get('resource') : null,
                    $resourceUrl
                ),
                'refresh_token' => $this->server->refresh(
                    $get('refresh_token'),
                    $clientId,
                    $get('scope') !== '' ? $get('scope') : null,
                    $resourceUrl
                ),
                '' => throw new OAuthException('invalid_request', 'grant_type requis.'),
                default => throw new OAuthException('unsupported_grant_type', 'grant_type non supporté : ' . $grant),
            };
        } catch (OAuthException $e) {
            $this->logger->info('[mcp-oauth] /token refusé : {err}', ['err' => $e->error]);

            return self::cors($this->oauthError($response, $e));
        }

        return self::cors(McpController::json($response, 200, $result))->withHeader('Pragma', 'no-cache');
    }

    public function revoke(Request $request, Response $response): Response
    {
        $params = self::formParams($request);
        $token = is_string($params['token'] ?? null) ? trim($params['token']) : '';
        $clientId = is_string($params['client_id'] ?? null) ? trim($params['client_id']) : null;
        $this->server->revoke($token, $clientId);

        return self::cors($response->withStatus(200)->withHeader('Cache-Control', 'no-store'));
    }

    public function preflight(Request $request, Response $response): Response
    {
        return self::cors($response->withStatus(204));
    }

    // ── Aides ─────────────────────────────────────────────────────────

    /**
     * Corps `application/x-www-form-urlencoded` (RFC 6749 §4.1.3), lu depuis le flux brut
     * si Slim ne l'a pas analysé (aucun BodyParsingMiddleware global n'est installé).
     *
     * @return array<mixed>
     */
    public static function formParams(Request $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw === '') {
            $raw = (string) $request->getAttribute('raw_post_body', '');
        }
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $json = json_decode($raw, true);

            return is_array($json) ? $json : [];
        }
        $out = [];
        parse_str($raw, $out);

        return $out;
    }

    /**
     * @return list<array{scope: string, label: string, granted: bool, note: ?string}>
     */
    private function describeScopes(string $requested): array
    {
        $list = preg_split('/\s+/', trim($requested)) ?: [];
        $list = array_values(array_filter($list, static fn (string $s): bool => $s !== ''));
        if ($list === []) {
            $list = [McpConfig::SCOPE_READ, McpConfig::SCOPE_OFFLINE];
        }
        if (!in_array(McpConfig::SCOPE_READ, $list, true)) {
            array_unshift($list, McpConfig::SCOPE_READ);
        }
        $labels = [
            McpConfig::SCOPE_READ => ['Lire les mesures, l\'état des modules, les alertes et les photos', null],
            McpConfig::SCOPE_CONTROL => ['Préparer et appliquer des changements de sorties (pompes, chauffage…) sur les environnements autorisés',
                $this->config->isControlEnabled() ? 'Réservé aux comptes opérateur et administrateur.' : 'Pilotage désactivé sur ce serveur : ce droit ne sera pas accordé.'],
            McpConfig::SCOPE_OFFLINE => ['Rester connecté (jeton de renouvellement, révocable)', null],
        ];
        $out = [];
        foreach ($list as $scope) {
            if (!isset($labels[$scope])) {
                continue;
            }
            $out[] = [
                'scope' => $scope,
                'label' => $labels[$scope][0],
                'granted' => $scope !== McpConfig::SCOPE_CONTROL || $this->config->isControlEnabled(),
                'note' => $labels[$scope][1],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderConsent(Response $response, array $context): Response
    {
        $context['mcp_csrf'] = $this->csrfToken();
        $context['roles_note'] = 'Comptes : ' . implode(', ', [User::roleLabel(User::ROLE_READER), User::roleLabel(User::ROLE_OPERATOR), User::roleLabel(User::ROLE_ADMIN)]) . '.';
        $response->getBody()->write($this->renderer->render('oauth/authorize.twig', $context));

        return self::noFraming($response->withHeader('Content-Type', 'text/html; charset=utf-8'));
    }

    private function errorPage(Response $response, string $title, string $message): Response
    {
        $response->getBody()->write($this->renderer->render('oauth/error.twig', [
            'page_title' => $title . ' — n³ IoT',
            'error_title' => $title,
            'error_message' => $message,
        ]));

        return self::noFraming($response->withStatus(400)->withHeader('Content-Type', 'text/html; charset=utf-8'));
    }

    private function csrfToken(): string
    {
        self::startSession();
        if (!isset($_SESSION[self::CSRF_SESSION_KEY]) || !is_string($_SESSION[self::CSRF_SESSION_KEY])) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_SESSION_KEY];
    }

    private function checkCsrf(string $submitted): bool
    {
        self::startSession();
        $expected = $_SESSION[self::CSRF_SESSION_KEY] ?? null;

        return is_string($expected) && $submitted !== '' && hash_equals($expected, $submitted);
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            \App\Security\AuthService::hardenSessionCookie();
            session_start();
        }
    }

    private function oauthError(Response $response, OAuthException $e): Response
    {
        $response = McpController::json($response, $e->httpStatus, $e->toArray());
        if ($e->httpStatus === 401) {
            $response = $response->withHeader('WWW-Authenticate', 'Basic realm="oauth"');
        }

        return $response;
    }

    private function redirectError(Response $response, string $redirectUri, OAuthException $e, string $state, string $issuer): Response
    {
        return self::redirect($response, $redirectUri, array_filter([
            'error' => $e->error,
            'error_description' => $e->getMessage(),
            'state' => $state !== '' ? $state : null,
            'iss' => $issuer,
        ], static fn ($v): bool => $v !== null));
    }

    /**
     * @param array<string, string> $params
     */
    private static function redirect(Response $response, string $uri, array $params): Response
    {
        $separator = str_contains($uri, '?') ? '&' : '?';

        return $response
            ->withStatus(302)
            ->withHeader('Location', $uri . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986))
            ->withHeader('Cache-Control', 'no-store');
    }

    private static function noFraming(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Frame-Options', 'DENY');
    }

    private static function cors(Response $response): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, MCP-Protocol-Version');
    }
}
