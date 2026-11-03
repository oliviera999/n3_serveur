<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Mcp\McpConfig;

/**
 * Validation stricte des URI de redirection OAuth.
 *
 * Politique serveur (avant même la comparaison au client) :
 *  - correspondance EXACTE avec une URI autorisée (`https://claude.ai/api/mcp/auth_callback`
 *    + `MCP_EXTRA_REDIRECT_URIS`) ;
 *  - OU redirection loopback `http://localhost/…`, `http://127.0.0.1/…`, `http://[::1]/…`
 *    (clients natifs, ex. Claude Code), PORT IGNORÉ à la comparaison (RFC 8252 §7.3,
 *    étendu à `localhost` comme l'exige Claude Code).
 *
 * Comparaison client ↔ requête : chaîne identique, ou — pour le loopback uniquement —
 * identique une fois le port retiré (schéma, hôte, chemin, requête). `localhost` et
 * `127.0.0.1` ne sont PAS interchangeables. Fragments et identifiants refusés.
 *
 * Référence : https://claude.com/docs/connectors/building/authentication#callback-urls
 */
final class RedirectUriValidator
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    public function __construct(private McpConfig $config)
    {
    }

    public function isAllowedByPolicy(string $uri): bool
    {
        if (!self::isWellFormed($uri)) {
            return false;
        }
        if (in_array($uri, $this->config->allowedRedirectUris(), true)) {
            return true;
        }

        return self::isLoopback($uri);
    }

    /**
     * @param list<string> $registered
     */
    public function matchesAny(array $registered, string $requested): bool
    {
        foreach ($registered as $candidate) {
            if (self::matches($candidate, $requested)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $registered, string $requested): bool
    {
        if (!self::isWellFormed($requested)) {
            return false;
        }
        if (hash_equals($registered, $requested)) {
            return true;
        }
        if (!self::isLoopback($registered) || !self::isLoopback($requested)) {
            return false;
        }

        return self::withoutPort($registered) === self::withoutPort($requested);
    }

    public static function isLoopback(string $uri): bool
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http' || !isset($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        return in_array(strtolower($parts['host']), self::LOOPBACK_HOSTS, true);
    }

    private static function isWellFormed(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 512 || preg_match('/[\s\x00-\x1f]/', $uri) === 1) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['https', 'http'], true)
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    private static function withoutPort(string $uri): string
    {
        $parts = (array) parse_url($uri);

        return strtolower((string) ($parts['scheme'] ?? '')) . '://' . strtolower((string) ($parts['host'] ?? ''))
            . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
