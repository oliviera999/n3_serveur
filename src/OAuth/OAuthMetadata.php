<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Mcp\McpConfig;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Documents de découverte OAuth du connecteur MCP.
 *
 *  - Protected Resource Metadata (RFC 9728) : `resource` = EXACTEMENT `<base>/mcp`,
 *    un seul serveur d'autorisation (Claude n'utilise que le premier) ;
 *  - Authorization Server Metadata (RFC 8414) : PKCE S256, client public (`none`),
 *    `client_id_metadata_document_supported: true` (CIMD), DCR, révocation.
 *
 * Exigences : https://claude.com/docs/connectors/building/authentication
 */
final class OAuthMetadata
{
    public static function baseUrl(McpConfig $config, ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $origin = $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
        $basePath = rtrim((string) ($GLOBALS['base_path'] ?? ''), '/');

        return $config->resolveBaseUrl($origin . $basePath);
    }

    public static function resourceUrl(string $base): string
    {
        return $base . '/mcp';
    }

    public static function protectedResourceMetadataUrl(string $base): string
    {
        return $base . '/.well-known/oauth-protected-resource/mcp';
    }

    /**
     * @return array<string, mixed>
     */
    public static function protectedResource(McpConfig $config, string $base): array
    {
        return [
            'resource' => self::resourceUrl($base),
            'authorization_servers' => [$base],
            'scopes_supported' => $config->resourceScopes(),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Serveur IoT n³ (iot.olution.info)',
            'resource_documentation' => $base . '/glossaire',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function authorizationServer(McpConfig $config, string $base): array
    {
        $metadata = [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'revocation_endpoint' => $base . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            // `none` = clients publics (Claude, Cursor, ChatGPT via DCR/CIMD) ;
            // `client_secret_post` / `client_secret_basic` = client confidentiel statique (.env, Gemini Enterprise).
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => array_merge($config->resourceScopes(), [McpConfig::SCOPE_OFFLINE]),
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => $base . '/glossaire',
        ];
        if ($config->isDcrEnabled()) {
            $metadata['registration_endpoint'] = $base . '/oauth/register';
        }

        return $metadata;
    }
}
