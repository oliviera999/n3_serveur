<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Mcp\McpConfig;
use App\Repository\McpOAuthRepository;

/**
 * Résout un `client_id` OAuth en client connu :
 *  - URL HTTPS → Client ID Metadata Document (draft-ietf-oauth-client-id-metadata-document,
 *    MCP 2025-11-25) récupéré à la volée (délai ≤ 3 s, ≤ 64 Kio, sans redirection), validé
 *    (`client_id` du document = URL, `redirect_uris`, client public) et mis en cache 1 h ;
 *  - sinon identifiant émis par l'enregistrement dynamique (RFC 7591, table mcp_oauth_clients).
 *
 * Défense SSRF : hôtes CIMD limités à `MCP_CIMD_ALLOWED_HOSTS` (défaut claude.ai,
 * claude.com) ; avec `*`, les adresses privées / loopback / link-local sont refusées.
 */
class ClientResolver
{
    public const CIMD_TIMEOUT_SECONDS = 3;
    public const CIMD_MAX_BYTES = 65536;
    public const CIMD_CACHE_SECONDS = 3600;

    public function __construct(
        private McpOAuthRepository $repo,
        private HttpFetcher $fetcher,
        private McpConfig $config,
    ) {
    }

    public static function isUrlClientId(string $clientId): bool
    {
        return str_starts_with(strtolower($clientId), 'https://');
    }

    /**
     * @return array{client_id: string, client_name: ?string, redirect_uris: list<string>, type: string, display_host: ?string}
     *
     * @throws OAuthException invalid_client
     */
    public function resolve(string $clientId): array
    {
        if ($clientId === '' || strlen($clientId) > 512) {
            throw new OAuthException('invalid_client', 'client_id manquant ou invalide.', 401);
        }
        if (self::isUrlClientId($clientId)) {
            return $this->resolveCimd($clientId);
        }
        $client = $this->repo->findClient($clientId);
        if ($client === null) {
            throw new OAuthException('invalid_client', 'Client inconnu : enregistrez-le d\'abord (/oauth/register).', 401);
        }

        return $client + ['type' => 'dcr', 'display_host' => null];
    }

    /**
     * @return array{client_id: string, client_name: ?string, redirect_uris: list<string>, type: string, display_host: ?string}
     */
    private function resolveCimd(string $url): array
    {
        $host = $this->validateCimdUrl($url);
        $document = $this->repo->findCimd($url);
        if ($document === null) {
            $document = $this->fetchDocument($url);
            $this->repo->storeCimd($url, $document, self::CIMD_CACHE_SECONDS);
        }
        /** @var list<string> $uris */
        $uris = array_values(array_filter((array) ($document['redirect_uris'] ?? []), 'is_string'));

        return [
            'client_id' => $url,
            'client_name' => is_string($document['client_name'] ?? null) ? mb_substr($document['client_name'], 0, 100) : null,
            'redirect_uris' => $uris,
            'type' => 'cimd',
            'display_host' => $host,
        ];
    }

    private function validateCimdUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])
            || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || ($parts['path'] ?? '/') === '/' || str_contains((string) ($parts['path'] ?? ''), '/../')) {
            throw new OAuthException('invalid_client', 'client_id URL invalide (HTTPS, chemin requis, sans fragment).', 401);
        }
        $host = strtolower($parts['host']);
        $allowed = $this->config->cimdAllowedHosts();
        if (!in_array('*', $allowed, true) && !in_array($host, $allowed, true)) {
            throw new OAuthException('invalid_client', 'Hôte de métadonnées client non autorisé : ' . $host, 401);
        }
        if (in_array('*', $allowed, true) && !self::isPublicHost($host)) {
            throw new OAuthException('invalid_client', 'Hôte de métadonnées client non public.', 401);
        }

        return $host;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $url): array
    {
        try {
            $response = $this->fetcher->get($url, self::CIMD_TIMEOUT_SECONDS, self::CIMD_MAX_BYTES);
        } catch (\RuntimeException $e) {
            throw new OAuthException('invalid_client', 'Document de métadonnées client injoignable : ' . $e->getMessage(), 401);
        }
        if ($response['status'] !== 200) {
            throw new OAuthException('invalid_client', 'Document de métadonnées client : HTTP ' . $response['status'], 401);
        }
        $document = json_decode($response['body'], true);
        if (!is_array($document) || array_is_list($document)) {
            throw new OAuthException('invalid_client', 'Document de métadonnées client : JSON invalide.', 401);
        }
        if (($document['client_id'] ?? null) !== $url) {
            throw new OAuthException('invalid_client', 'Le client_id du document ne correspond pas à son URL.', 401);
        }
        $uris = $document['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > 10) {
            throw new OAuthException('invalid_client', 'Document de métadonnées client : redirect_uris manquant.', 401);
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || $uri === '') {
                throw new OAuthException('invalid_client', 'Document de métadonnées client : redirect_uri invalide.', 401);
            }
        }
        $method = $document['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            throw new OAuthException('invalid_client', 'Seuls les clients publics (token_endpoint_auth_method=none) sont acceptés.', 401);
        }

        return $document;
    }

    private static function isPublicHost(string $host): bool
    {
        if (in_array($host, ['localhost'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (@gethostbynamel($host) ?: []);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
