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
 *  - sinon client confidentiel statique `.env` (Gemini Enterprise) ;
 *  - sinon identifiant émis par l'enregistrement dynamique (RFC 7591, table mcp_oauth_clients).
 *
 * Défense SSRF : hôtes CIMD limités à `MCP_CIMD_ALLOWED_HOSTS` ; avec `*`, les adresses
 * privées / loopback / link-local sont refusées.
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
     * @return array{client_id: string, client_name: ?string, redirect_uris: list<string>, type: string, display_host: ?string, token_endpoint_auth_method?: string}
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
        $confidential = $this->config->confidentialClient();
        if ($confidential !== null && hash_equals($confidential['client_id'], $clientId)) {
            return $confidential;
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
        [$host, $pinnedIp] = $this->validateCimdUrl($url);
        $document = $this->repo->findCimd($url);
        if ($document === null) {
            $document = $this->fetchDocument($url, $pinnedIp);
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

    /**
     * @return array{0: string, 1: ?string} [hôte, IP épinglée (mode `*` uniquement)]
     */
    private function validateCimdUrl(string $url): array
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
        $pinned = null;
        if (in_array('*', $allowed, true)) {
            // Mode ouvert (déconseillé) : l'hôte doit résoudre vers des IP PUBLIQUES
            // uniquement, et la requête est épinglée sur l'IP vérifiée (anti DNS-rebinding).
            $pinned = self::resolvePublicIp($host);
            if ($pinned === null) {
                throw new OAuthException('invalid_client', 'Hôte de métadonnées client non public.', 401);
            }
        }

        return [$host, $pinned];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDocument(string $url, ?string $pinnedIp): array
    {
        try {
            $response = $this->fetcher->get($url, self::CIMD_TIMEOUT_SECONDS, self::CIMD_MAX_BYTES, $pinnedIp);
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

    /**
     * Première IP de l'hôte si TOUTES ses adresses (A et AAAA) sont publiques, sinon null.
     * Refuse les plages privées, réservées, loopback, link-local, ULA IPv6 (fc00::/7),
     * IPv4 mappées / NAT64 vers une adresse privée.
     */
    public static function resolvePublicIp(string $host, ?callable $resolver = null): ?string
    {
        $host = trim($host, '[]');
        if (in_array($host, ['localhost'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } elseif ($resolver !== null) {
            $ips = $resolver($host);
        } else {
            $ips = [];
            foreach ([DNS_A, DNS_AAAA] as $type) {
                foreach ((array) @dns_get_record($host, $type) as $record) {
                    $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                    if (is_string($ip)) {
                        $ips[] = $ip;
                    }
                }
            }
        }
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp((string) $ip)) {
                return null;
            }
        }

        return (string) $ips[0];
    }

    /**
     * Plages IPv4 non publiques (RFC 6890 / registre IANA « special-purpose ») : adresse réseau
     * et longueur de préfixe. Liste explicite pour un résultat identique quelle que soit la
     * version de PHP ou de la libc (le comportement de `filter_var(FILTER_FLAG_NO_RES_RANGE)`
     * a varié d'une version à l'autre, notamment sur les IPv4 mappées en IPv6).
     */
    private const NON_PUBLIC_IPV4 = [
        ['0.0.0.0', 8],          // « ce réseau »
        ['10.0.0.0', 8],         // privé
        ['100.64.0.0', 10],      // CGNAT
        ['127.0.0.0', 8],        // boucle locale
        ['169.254.0.0', 16],     // lien local
        ['172.16.0.0', 12],      // privé
        ['192.0.0.0', 24],       // IETF
        ['192.0.2.0', 24],       // documentation
        ['192.88.99.0', 24],     // relais 6to4 (obsolète)
        ['192.168.0.0', 16],     // privé
        ['198.18.0.0', 15],      // bancs d'essai
        ['198.51.100.0', 24],    // documentation
        ['203.0.113.0', 24],     // documentation
        ['224.0.0.0', 4],        // multicast
        ['240.0.0.0', 4],        // réservé + diffusion
    ];

    /**
     * Vrai si l'adresse est routable publiquement. Les IPv6 qui embarquent une IPv4
     * (`::ffff:a.b.c.d` mappée, sous forme pointée ou hexadécimale `::ffff:808:808`, et
     * `64:ff9b::/96` NAT64) sont jugées sur l'IPv4 extraite, sans passer par `filter_var`.
     */
    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            return self::isPublicIpv4($bin);
        }
        if (strlen($bin) !== 16) {
            return false;
        }
        $prefix = substr($bin, 0, 12);
        // ::ffff:0:0/96 (IPv4 mappée) et 64:ff9b::/96 (NAT64) : on juge l'IPv4 embarquée.
        if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
            return self::isPublicIpv4(substr($bin, 12));
        }
        // ::/96 (dont :: et ::1, et les IPv4 « compatibles » obsolètes) : jamais publique.
        if ($prefix === str_repeat("\0", 12)) {
            return false;
        }
        $first = ord($bin[0]);
        // fc00::/7 (ULA), fe80::/10 (lien local), fec0::/10 (site local obsolète), ff00::/8 (multicast)
        if (($first & 0xFE) === 0xFC || ($first === 0xFE && (ord($bin[1]) & 0x80) === 0x80) || $first === 0xFF) {
            return false;
        }
        // 2001:db8::/32 (documentation), 2002::/16 (6to4, IPv4 embarquée arbitraire), 100::/64 (discard)
        if (substr($bin, 0, 4) === "\x20\x01\x0d\xb8" || substr($bin, 0, 2) === "\x20\x02" || substr($bin, 0, 8) === "\x01\x00" . str_repeat("\0", 6)) {
            return false;
        }
        // Seul l'espace unicast global 2000::/3 est attribué au public.
        return ($first & 0xE0) === 0x20;
    }

    private static function isPublicIpv4(string $bin): bool
    {
        $addr = unpack('N', $bin);
        if ($addr === false) {
            return false;
        }
        $value = (int) $addr[1];
        foreach (self::NON_PUBLIC_IPV4 as [$network, $bits]) {
            $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
            if (($value & $mask) === ((int) ip2long($network) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
