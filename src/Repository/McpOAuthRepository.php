<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Persistance du serveur d'autorisation OAuth du connecteur MCP et des plans de pilotage.
 *
 * Tables (migrations/2026_11_mcp_oauth.sql) : `mcp_oauth_clients`, `mcp_oauth_cimd_cache`,
 * `mcp_oauth_codes`, `mcp_oauth_tokens`, `mcp_control_plans`. Les jetons et codes ne sont
 * JAMAIS stockés en clair : seuls leurs condensats sha256 (`*_hash`) le sont.
 *
 * Les dates sont produites côté PHP (`Y-m-d H:i:s`, fuseau applicatif) pour rester
 * portables (MariaDB en production, SQLite en tests unitaires).
 */
class McpOAuthRepository extends AbstractRepository
{
    // ── Clients DCR ───────────────────────────────────────────────────

    /**
     * @param list<string> $redirectUris
     */
    public function createClient(string $clientId, ?string $name, array $redirectUris, ?string $softwareId): void
    {
        $this->execute(
            'INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris, grant_types, token_endpoint_auth_method, software_id, created_at)'
            . ' VALUES (:id, :name, :uris, :grants, :method, :software, :created)',
            [
                ':id' => $clientId,
                ':name' => $name,
                ':uris' => (string) json_encode(array_values($redirectUris), JSON_UNESCAPED_SLASHES),
                ':grants' => 'authorization_code,refresh_token',
                ':method' => 'none',
                ':software' => $softwareId,
                ':created' => self::now(),
            ]
        );
    }

    /**
     * @return array{client_id: string, client_name: ?string, redirect_uris: list<string>}|null
     */
    public function findClient(string $clientId): ?array
    {
        $row = $this->fetchOne(
            'SELECT client_id, client_name, redirect_uris FROM mcp_oauth_clients WHERE client_id = :id LIMIT 1',
            [':id' => $clientId]
        );
        if ($row === null) {
            return null;
        }
        $uris = json_decode((string) $row['redirect_uris'], true);

        return [
            'client_id' => (string) $row['client_id'],
            'client_name' => $row['client_name'] !== null ? (string) $row['client_name'] : null,
            'redirect_uris' => is_array($uris) ? array_values(array_map('strval', $uris)) : [],
        ];
    }

    public function touchClient(string $clientId): void
    {
        $this->execute(
            'UPDATE mcp_oauth_clients SET last_used_at = :now WHERE client_id = :id',
            [':now' => self::now(), ':id' => $clientId]
        );
    }

    // ── Cache CIMD ────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null Document mis en cache encore valide
     */
    public function findCimd(string $url): ?array
    {
        $row = $this->fetchOne(
            'SELECT document FROM mcp_oauth_cimd_cache WHERE url_hash = :h AND expires_at > :now LIMIT 1',
            [':h' => hash('sha256', $url), ':now' => self::now()]
        );
        if ($row === null) {
            return null;
        }
        $doc = json_decode((string) $row['document'], true);

        return is_array($doc) ? $doc : null;
    }

    /**
     * @param array<string, mixed> $document
     */
    public function storeCimd(string $url, array $document, int $ttlSeconds): void
    {
        $hash = hash('sha256', $url);
        $this->execute('DELETE FROM mcp_oauth_cimd_cache WHERE url_hash = :h', [':h' => $hash]);
        $this->execute(
            'INSERT INTO mcp_oauth_cimd_cache (url_hash, client_id, document, fetched_at, expires_at)'
            . ' VALUES (:h, :id, :doc, :now, :exp)',
            [
                ':h' => $hash,
                ':id' => mb_substr($url, 0, 512),
                ':doc' => (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':now' => self::now(),
                ':exp' => self::at(time() + $ttlSeconds),
            ]
        );
    }

    // ── Codes d'autorisation ──────────────────────────────────────────

    public function storeCode(
        string $codeHash,
        string $clientId,
        int $userId,
        string $redirectUri,
        string $scope,
        ?string $resource,
        string $codeChallenge,
        int $ttlSeconds,
    ): void {
        $this->execute(
            'INSERT INTO mcp_oauth_codes (code_hash, client_id, user_id, redirect_uri, scope, resource, code_challenge, expires_at, created_at)'
            . ' VALUES (:h, :c, :u, :r, :s, :res, :cc, :exp, :now)',
            [
                ':h' => $codeHash,
                ':c' => $clientId,
                ':u' => $userId,
                ':r' => $redirectUri,
                ':s' => $scope,
                ':res' => $resource,
                ':cc' => $codeChallenge,
                ':exp' => self::at(time() + $ttlSeconds),
                ':now' => self::now(),
            ]
        );
    }

    /**
     * Consomme un code (usage unique, atomique). Retourne la ligne si le code était
     * valide et non utilisé, null sinon (inexistant, expiré ou déjà consommé).
     *
     * @return array<string, mixed>|null
     */
    public function consumeCode(string $codeHash): ?array
    {
        $row = $this->fetchOne(
            'SELECT code_hash, client_id, user_id, redirect_uri, scope, resource, code_challenge, expires_at, used_at'
            . ' FROM mcp_oauth_codes WHERE code_hash = :h LIMIT 1',
            [':h' => $codeHash]
        );
        if ($row === null) {
            return null;
        }
        // Marquage atomique : seule la première requête concurrente obtient rowCount = 1.
        $claimed = $this->executeWithRowCount(
            'UPDATE mcp_oauth_codes SET used_at = :now WHERE code_hash = :h AND used_at IS NULL',
            [':now' => self::now(), ':h' => $codeHash]
        );
        $row['already_used'] = $claimed !== 1;

        return $row;
    }

    // ── Jetons ────────────────────────────────────────────────────────

    public function storeToken(
        string $tokenHash,
        string $type,
        string $familyId,
        string $clientId,
        int $userId,
        string $scope,
        ?string $resource,
        int $ttlSeconds,
    ): void {
        $this->execute(
            'INSERT INTO mcp_oauth_tokens (token_hash, token_type, family_id, client_id, user_id, scope, resource, expires_at, created_at)'
            . ' VALUES (:h, :t, :f, :c, :u, :s, :r, :exp, :now)',
            [
                ':h' => $tokenHash,
                ':t' => $type,
                ':f' => $familyId,
                ':c' => $clientId,
                ':u' => $userId,
                ':s' => $scope,
                ':r' => $resource,
                ':exp' => self::at(time() + $ttlSeconds),
                ':now' => self::now(),
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findToken(string $tokenHash): ?array
    {
        return $this->fetchOne(
            'SELECT token_hash, token_type, family_id, client_id, user_id, scope, resource, expires_at, revoked_at'
            . ' FROM mcp_oauth_tokens WHERE token_hash = :h LIMIT 1',
            [':h' => $tokenHash]
        );
    }

    /**
     * Révoque un jeton précis ; retourne vrai si CETTE requête l'a révoqué (atomique).
     */
    public function revokeToken(string $tokenHash): bool
    {
        return $this->executeWithRowCount(
            'UPDATE mcp_oauth_tokens SET revoked_at = :now WHERE token_hash = :h AND revoked_at IS NULL',
            [':now' => self::now(), ':h' => $tokenHash]
        ) === 1;
    }

    public function revokeFamily(string $familyId): int
    {
        return $this->executeWithRowCount(
            'UPDATE mcp_oauth_tokens SET revoked_at = :now WHERE family_id = :f AND revoked_at IS NULL',
            [':now' => self::now(), ':f' => $familyId]
        );
    }

    public function touchToken(string $tokenHash): void
    {
        $this->execute(
            'UPDATE mcp_oauth_tokens SET last_used_at = :now WHERE token_hash = :h',
            [':now' => self::now(), ':h' => $tokenHash]
        );
    }

    // ── Plans de pilotage ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    public function storePlan(
        string $planId,
        int $userId,
        string $clientId,
        string $env,
        string $family,
        int $gpio,
        string $action,
        ?string $before,
        ?string $target,
        array $payload,
        int $ttlSeconds,
    ): void {
        $this->execute(
            'INSERT INTO mcp_control_plans (plan_id, user_id, client_id, env, family, gpio, action, before_state, target_state, payload, expires_at, created_at)'
            . ' VALUES (:id, :u, :c, :env, :fam, :gpio, :action, :before, :target, :payload, :exp, :now)',
            [
                ':id' => $planId,
                ':u' => $userId,
                ':c' => $clientId,
                ':env' => $env,
                ':fam' => $family,
                ':gpio' => $gpio,
                ':action' => $action,
                ':before' => $before,
                ':target' => $target,
                ':payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':exp' => self::at(time() + $ttlSeconds),
                ':now' => self::now(),
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlan(string $planId): ?array
    {
        return $this->fetchOne(
            'SELECT plan_id, user_id, client_id, env, family, gpio, action, before_state, target_state, payload, expires_at, applied_at'
            . ' FROM mcp_control_plans WHERE plan_id = :id LIMIT 1',
            [':id' => $planId]
        );
    }

    /**
     * Marque un plan comme appliqué (atomique) : vrai si CETTE requête l'a réclamé.
     */
    public function claimPlan(string $planId): bool
    {
        return $this->executeWithRowCount(
            'UPDATE mcp_control_plans SET applied_at = :now WHERE plan_id = :id AND applied_at IS NULL AND expires_at > :now2',
            [':now' => self::now(), ':id' => $planId, ':now2' => self::now()]
        ) === 1;
    }

    /**
     * Nombre de nourrissages MCP appliqués depuis $since (plafond quotidien).
     */
    public function countAppliedFeeds(string $env, string $family, string $since): int
    {
        return (int) $this->fetchScalar(
            "SELECT COUNT(*) FROM mcp_control_plans WHERE env = :env AND family = :fam AND action = 'feed'"
            . ' AND applied_at IS NOT NULL AND applied_at >= :since',
            [':env' => $env, ':fam' => $family, ':since' => $since]
        );
    }

    // ── Purge (CRON) ──────────────────────────────────────────────────

    /**
     * Supprime les lignes expirées (marge d'un jour pour les jetons, afin de garder la
     * détection de réutilisation d'un refresh récemment tourné).
     *
     * @return array<string, int>
     */
    public function purgeExpired(): array
    {
        $now = self::now();
        $dayAgo = self::at(time() - 86400);

        return [
            'codes' => $this->executeWithRowCount('DELETE FROM mcp_oauth_codes WHERE expires_at < :t', [':t' => $dayAgo]),
            'tokens' => $this->executeWithRowCount('DELETE FROM mcp_oauth_tokens WHERE expires_at < :t', [':t' => $dayAgo]),
            'cimd' => $this->executeWithRowCount('DELETE FROM mcp_oauth_cimd_cache WHERE expires_at < :t', [':t' => $now]),
            'plans' => $this->executeWithRowCount(
                'DELETE FROM mcp_control_plans WHERE expires_at < :t AND (applied_at IS NULL OR applied_at < :old)',
                [':t' => $dayAgo, ':old' => self::at(time() - 30 * 86400)]
            ),
            'clients' => $this->executeWithRowCount(
                'DELETE FROM mcp_oauth_clients WHERE created_at < :old AND (last_used_at IS NULL OR last_used_at < :old2)'
                . ' AND client_id NOT IN (SELECT DISTINCT client_id FROM mcp_oauth_tokens)',
                [':old' => self::at(time() - 90 * 86400), ':old2' => self::at(time() - 90 * 86400)]
            ),
        ];
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function at(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
}
