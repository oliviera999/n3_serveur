<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Config\Env;

/**
 * Configuration du connecteur MCP et de son serveur d'autorisation OAuth embarqué.
 *
 * Lue une fois depuis l'environnement (`.env`) par {@see fromEnv()} ; le constructeur
 * accepte un tableau brut pour les tests (aucune lecture globale).
 *
 * Variables (voir `.env.example` et `docs/MCP.md`) :
 *  - MCP_ENABLED                 : active les routes /mcp, /oauth/*, /.well-known/oauth-* (défaut false)
 *  - MCP_PUBLIC_BASE_URL         : URL publique du serveur, sans slash final (ex. https://iot.olution.info)
 *  - MCP_ACCESS_TOKEN_TTL        : durée de vie du jeton d'accès en secondes (défaut 3600)
 *  - MCP_REFRESH_TOKEN_TTL       : durée de vie du jeton de rafraîchissement (défaut 30 j)
 *  - MCP_AUTH_CODE_TTL           : durée de vie du code d'autorisation (défaut 60 s)
 *  - MCP_ALLOWED_ENVS            : environnements lisibles par les outils (défaut prod,s3)
 *  - MCP_CONTROL_ENABLED         : expose les outils de pilotage (défaut false)
 *  - MCP_CONTROL_ALLOWED_ENVS    : environnements pilotables (défaut : environnements de test)
 *  - MCP_EXTRA_REDIRECT_URIS     : redirections OAuth supplémentaires acceptées (CSV, exactes)
 *  - MCP_ALLOWED_ORIGINS         : origines navigateur supplémentaires acceptées sur /mcp (CSV)
 *  - MCP_CIMD_ALLOWED_HOSTS      : hôtes autorisés pour les Client ID Metadata Documents (CSV, `*` = tout hôte public)
 *  - MCP_DCR_ENABLED             : active l'enregistrement dynamique /oauth/register (défaut true)
 *  - MCP_RATE_LIMIT_MAX / _WINDOW: limitation de débit de /mcp par IP (défaut 300 / 60 s)
 *  - MCP_FEED_DAILY_MAX          : nombre maximal de nourrissages MCP par 24 h et par environnement (défaut 2)
 */
final class McpConfig
{
    /** Environnements de test (tables suffixées) — miroir de TableConfig::isTest(). */
    public const TEST_ENVIRONMENTS = ['test', 'test3', 's3test', 'n3pp_test', 'msp_test', 'energie_test'];

    /** Tous les environnements connus — miroir de TableConfig::ENVIRONMENTS. */
    public const ALL_ENVIRONMENTS = ['prod', 'test', 'test3', 's3', 's3test', 'n3pp_test', 'msp_test', 'energie_test'];

    /** Redirection unique des applications hébergées Claude (claude.ai, Desktop, mobile, Cowork). */
    public const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    /** Redirections loopback de Claude Code (port ignoré à la comparaison, RFC 8252 §7.3). */
    public const LOOPBACK_CALLBACKS = ['http://localhost/callback', 'http://127.0.0.1/callback'];

    public const SCOPE_READ = 'iot.read';
    public const SCOPE_CONTROL = 'iot.control';
    public const SCOPE_OFFLINE = 'offline_access';

    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, scalar|null> $values Variables brutes (clés MCP_*)
     */
    public function __construct(array $values = [])
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[(string) $key] = $value === null ? '' : trim((string) $value);
        }
        $this->values = $normalized;
    }

    public static function fromEnv(): self
    {
        Env::load();
        $keys = [
            'MCP_ENABLED', 'MCP_PUBLIC_BASE_URL', 'MCP_ACCESS_TOKEN_TTL', 'MCP_REFRESH_TOKEN_TTL',
            'MCP_AUTH_CODE_TTL', 'MCP_ALLOWED_ENVS', 'MCP_CONTROL_ENABLED', 'MCP_CONTROL_ALLOWED_ENVS',
            'MCP_EXTRA_REDIRECT_URIS', 'MCP_ALLOWED_ORIGINS', 'MCP_CIMD_ALLOWED_HOSTS', 'MCP_DCR_ENABLED',
            'MCP_RATE_LIMIT_MAX', 'MCP_RATE_LIMIT_WINDOW', 'MCP_FEED_DAILY_MAX',
        ];
        $values = [];
        foreach ($keys as $key) {
            $raw = $_ENV[$key] ?? getenv($key);
            if (is_scalar($raw) && $raw !== false) {
                $values[$key] = (string) $raw;
            }
        }

        return new self($values);
    }

    public function isEnabled(): bool
    {
        return $this->bool('MCP_ENABLED', false);
    }

    /**
     * URL publique configurée (sans slash final), ou '' si absente.
     */
    public function publicBaseUrl(): string
    {
        return rtrim($this->string('MCP_PUBLIC_BASE_URL', ''), '/');
    }

    /**
     * URL de base effective : la valeur configurée, sinon celle déduite de la requête
     * (`scheme://host[:port]`) — repli de développement, documenté dans docs/MCP.md.
     */
    public function resolveBaseUrl(string $requestOrigin): string
    {
        $configured = $this->publicBaseUrl();

        return $configured !== '' ? $configured : rtrim($requestOrigin, '/');
    }

    public function accessTokenTtl(): int
    {
        return $this->boundedInt('MCP_ACCESS_TOKEN_TTL', 3600, 300, 86400);
    }

    public function refreshTokenTtl(): int
    {
        return $this->boundedInt('MCP_REFRESH_TOKEN_TTL', 30 * 86400, 3600, 180 * 86400);
    }

    public function authCodeTtl(): int
    {
        return $this->boundedInt('MCP_AUTH_CODE_TTL', 60, 30, 600);
    }

    /**
     * Environnements lisibles (intersection avec les environnements connus, défaut prod,s3).
     *
     * @return list<string>
     */
    public function allowedEnvs(): array
    {
        $envs = $this->csv('MCP_ALLOWED_ENVS', ['prod', 's3']);
        $envs = array_values(array_intersect($envs, self::ALL_ENVIRONMENTS));

        return $envs !== [] ? $envs : ['prod'];
    }

    public function isControlEnabled(): bool
    {
        return $this->bool('MCP_CONTROL_ENABLED', false);
    }

    /**
     * Environnements pilotables. Défaut : environnements de TEST uniquement. Une valeur
     * de production (`prod`, `s3`) n'est retenue que si elle est explicitement listée.
     *
     * @return list<string>
     */
    public function controlAllowedEnvs(): array
    {
        $envs = $this->csv('MCP_CONTROL_ALLOWED_ENVS', self::TEST_ENVIRONMENTS);

        return array_values(array_intersect($envs, self::ALL_ENVIRONMENTS));
    }

    /**
     * Redirections OAuth exactes acceptées (hors loopback, comparé sans le port).
     *
     * @return list<string>
     */
    public function allowedRedirectUris(): array
    {
        return array_values(array_unique(array_merge(
            [self::CLAUDE_CALLBACK],
            $this->csv('MCP_EXTRA_REDIRECT_URIS', [])
        )));
    }

    /**
     * Origines navigateur acceptées sur /mcp (anti DNS-rebinding). Une requête SANS
     * en-tête Origin (serveur à serveur, Claude) n'est pas concernée.
     *
     * @return list<string>
     */
    public function allowedOrigins(string $baseUrl): array
    {
        $origins = ['https://claude.ai', 'https://claude.com'];
        $baseOrigin = self::originOf($baseUrl);
        if ($baseOrigin !== null) {
            $origins[] = $baseOrigin;
        }
        foreach ($this->csv('MCP_ALLOWED_ORIGINS', []) as $origin) {
            $normalized = self::originOf($origin);
            if ($normalized !== null) {
                $origins[] = $normalized;
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * @return list<string> hôtes autorisés pour les CIMD ; `*` = tout hôte public
     */
    public function cimdAllowedHosts(): array
    {
        return array_map('strtolower', $this->csv('MCP_CIMD_ALLOWED_HOSTS', ['claude.ai', 'claude.com']));
    }

    public function isDcrEnabled(): bool
    {
        return $this->bool('MCP_DCR_ENABLED', true);
    }

    public function rateLimitMax(): int
    {
        return $this->boundedInt('MCP_RATE_LIMIT_MAX', 300, 10, 100000);
    }

    public function rateLimitWindow(): int
    {
        return $this->boundedInt('MCP_RATE_LIMIT_WINDOW', 60, 10, 3600);
    }

    public function feedDailyMax(): int
    {
        return $this->boundedInt('MCP_FEED_DAILY_MAX', 2, 0, 6);
    }

    /**
     * Scopes annoncés par le serveur de ressources (iot.control seulement si le pilotage est actif).
     *
     * @return list<string>
     */
    public function resourceScopes(): array
    {
        return $this->isControlEnabled()
            ? [self::SCOPE_READ, self::SCOPE_CONTROL]
            : [self::SCOPE_READ];
    }

    /**
     * Normalise une URL en origine `scheme://host[:port]` (port par défaut omis), ou null.
     */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            return $scheme . '://' . $host . ':' . $port;
        }

        return $scheme . '://' . $host;
    }

    private function string(string $key, string $default): string
    {
        $value = $this->values[$key] ?? '';

        return $value !== '' ? $value : $default;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->values[$key] ?? '';
        if ($value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function boundedInt(string $key, int $default, int $min, int $max): int
    {
        $value = $this->values[$key] ?? '';
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private function csv(string $key, array $default): array
    {
        $value = $this->values[$key] ?? '';
        if ($value === '') {
            return $default;
        }
        $items = array_map('trim', explode(',', $value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }
}
