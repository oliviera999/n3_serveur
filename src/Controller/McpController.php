<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mcp\InsufficientScopeException;
use App\Mcp\JsonRpc\JsonRpcException;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\OAuth\AuthorizationServer;
use App\OAuth\OAuthMetadata;
use App\Repository\McpOAuthRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Point d'entrée MCP `POST /mcp` (Streamable HTTP, sans état, réponses JSON).
 *
 * Ordre des contrôles :
 *  1. en-tête `Origin` (anti DNS-rebinding) : présent et non autorisé → 403 ;
 *  2. jeton Bearer (jeton MCP UNIQUEMENT — la session web et ADMIN_TOKEN ne valent rien ici) :
 *     absent / invalide → 401 + `WWW-Authenticate: Bearer resource_metadata="…"` ;
 *  3. `MCP-Protocol-Version` : si présent, doit être supporté → sinon 400 ;
 *  4. corps JSON-RPC → {@see McpServer}. Que des notifications → 202 sans corps.
 *
 * `GET /mcp` et `DELETE /mcp` → 405 (pas de flux SSE ni de session à fermer).
 */
final class McpController
{
    public function __construct(
        private McpServer $server,
        private AuthorizationServer $authServer,
        private McpOAuthRepository $oauthRepo,
        private McpConfig $config,
    ) {
    }

    public function post(Request $request, Response $response): Response
    {
        $base = OAuthMetadata::baseUrl($this->config, $request);
        $resource = OAuthMetadata::resourceUrl($base);

        if (!$this->isOriginAllowed($request, $base)) {
            return self::json($response, 403, ['error' => 'forbidden_origin', 'error_description' => 'Origine non autorisée.']);
        }

        $token = self::bearerToken($request);
        $auth = $token !== null ? $this->authServer->validateAccessToken($token, $resource) : null;
        if ($auth === null) {
            $challenge = sprintf('Bearer resource_metadata="%s"', OAuthMetadata::protectedResourceMetadataUrl($base));
            if ($token !== null) {
                $challenge = 'Bearer error="invalid_token", error_description="Jeton absent, invalide ou expiré", '
                    . sprintf('resource_metadata="%s"', OAuthMetadata::protectedResourceMetadataUrl($base));
            }
            $challenge .= sprintf(', scope="%s"', implode(' ', $this->config->resourceScopes()));

            return self::json($response, 401, [
                'error' => 'invalid_token',
                'error_description' => 'Authentification requise (OAuth 2.1, voir resource_metadata).',
            ])->withHeader('WWW-Authenticate', $challenge);
        }

        $version = $request->getHeaderLine('MCP-Protocol-Version');
        if ($version !== '' && !McpServer::isSupportedProtocolVersion($version)) {
            return self::json($response, 400, McpServer::error(null, new JsonRpcException(
                JsonRpcException::INVALID_REQUEST,
                'Version de protocole MCP non supportée : ' . $version . '. Supportées : '
                . implode(', ', McpServer::SUPPORTED_PROTOCOL_VERSIONS)
            )));
        }

        $raw = (string) $request->getBody();
        if ($raw === '') {
            $raw = (string) $request->getAttribute('raw_post_body', '');
        }
        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::json($response, 400, McpServer::error(null, new JsonRpcException(JsonRpcException::PARSE_ERROR, 'JSON invalide.')));
        }
        if ($payload === [] || (!is_array($payload))) {
            return self::json($response, 400, McpServer::error(null, new JsonRpcException(JsonRpcException::INVALID_REQUEST, 'Requête JSON-RPC vide ou invalide.')));
        }

        $user = $auth['user'];
        $context = new McpContext($user->id, $user->username, $user->role, $auth['scopes'], $auth['client_id'], $request);
        $this->oauthRepo->touchToken($auth['token_hash']);

        try {
            $result = $this->server->handle($payload, $context);
        } catch (InsufficientScopeException $e) {
            return self::json($response, 403, [
                'error' => 'insufficient_scope',
                'error_description' => 'Ce jeton ne porte pas le scope ' . $e->scope . '.',
            ])->withHeader('WWW-Authenticate', sprintf(
                'Bearer error="insufficient_scope", scope="%s", resource_metadata="%s"',
                implode(' ', [McpConfig::SCOPE_READ, McpConfig::SCOPE_CONTROL, McpConfig::SCOPE_OFFLINE]),
                OAuthMetadata::protectedResourceMetadataUrl($base)
            ));
        }

        if ($result === null) {
            return $response->withStatus(202);
        }

        return self::json($response, 200, $result);
    }

    public function methodNotAllowed(Request $request, Response $response): Response
    {
        return self::json($response, 405, [
            'error' => 'method_not_allowed',
            'error_description' => 'Serveur MCP sans état : seule la méthode POST est servie (pas de flux SSE ni de session).',
        ])->withHeader('Allow', 'POST');
    }

    private function isOriginAllowed(Request $request, string $base): bool
    {
        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '' || $origin === 'null') {
            return $origin === '';
        }
        $normalized = McpConfig::originOf($origin);

        return $normalized !== null && in_array($normalized, $this->config->allowedOrigins($base), true);
    }

    /**
     * Jeton porté par `Authorization: Bearer …` (en-tête uniquement, jamais l'URL).
     * Sous PHP-FPM/CGI, Apache transmet l'en-tête via HTTP_AUTHORIZATION ou
     * REDIRECT_HTTP_AUTHORIZATION (cf. .htaccess) ; Slim les remonte dans les en-têtes PSR-7.
     */
    public static function bearerToken(Request $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            $server = $request->getServerParams();
            $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        if (preg_match('/^Bearer\s+([A-Za-z0-9._~+\/=-]+)\s*$/i', trim($header), $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function json(Response $response, int $status, array $data): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
