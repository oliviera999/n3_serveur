<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Domain\User;
use App\Mcp\McpConfig;
use App\Repository\McpOAuthRepository;
use App\Repository\UserRepository;

/**
 * Serveur d'autorisation OAuth 2.1 embarqué du connecteur MCP (logique métier, sans HTTP).
 *
 * - Grant `authorization_code` + PKCE S256 obligatoire, code à usage unique (~60 s) lié
 *   au client et à la `redirect_uri` ; réutilisation d'un code → révocation des jetons émis.
 * - Grant `refresh_token` avec ROTATION : chaque refresh est révoqué à l'usage et remplacé ;
 *   présenter un refresh déjà tourné révoque toute la famille (vol présumé, OAuth 2.1 §4.3.1).
 * - Jetons opaques (32 octets aléatoires, base64url) stockés hachés (sha256).
 * - Scopes : `iot.read` (reader+), `iot.control` (operator+ ET MCP_CONTROL_ENABLED),
 *   `offline_access` (délivre un refresh token).
 *
 * Implémentation maison, inspirée des comportements de `league/oauth2-server`
 * (https://github.com/thephpleague/oauth2-server, MIT) et de la spécification
 * OAuth 2.1 (draft-ietf-oauth-v2-1) ; aucun code repris. Pourquoi ne pas utiliser
 * league/oauth2-server : PHP ≥ 8.2 depuis la v9 (le projet cible 8.1), jetons JWT
 * signés (clé privée à gérer sur mutualisé), pas de support CIMD ni de rotation
 * avec détection de réutilisation sans code applicatif conséquent.
 */
class AuthorizationServer
{
    public const TOKEN_TYPE_ACCESS = 'access';
    public const TOKEN_TYPE_REFRESH = 'refresh';

    public function __construct(
        private McpOAuthRepository $repo,
        private UserRepository $users,
        private McpConfig $config,
    ) {
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * Scopes accordés pour un rôle, à partir de la demande (vide = défaut).
     *
     * @return list<string>
     */
    public function grantableScopes(string $requested, string $role): array
    {
        $requestedList = array_values(array_filter(preg_split('/\s+/', trim($requested)) ?: [], static fn (string $s): bool => $s !== ''));
        if ($requestedList === []) {
            $requestedList = [McpConfig::SCOPE_READ, McpConfig::SCOPE_OFFLINE];
        }
        $known = [McpConfig::SCOPE_READ, McpConfig::SCOPE_CONTROL, McpConfig::SCOPE_OFFLINE];
        $granted = [];
        foreach ($requestedList as $scope) {
            if (!in_array($scope, $known, true)) {
                continue; // scopes inconnus ignorés (RFC 6749 §3.3 : le serveur peut restreindre)
            }
            if ($scope === McpConfig::SCOPE_CONTROL
                && (!$this->config->isControlScopeOffered() || !in_array($role, [User::ROLE_OPERATOR, User::ROLE_ADMIN], true))) {
                continue;
            }
            $granted[] = $scope;
        }
        // iot.read est la base : tout utilisateur actif (reader+) l'obtient.
        if (!in_array(McpConfig::SCOPE_READ, $granted, true)) {
            array_unshift($granted, McpConfig::SCOPE_READ);
        }

        return array_values(array_unique($granted));
    }

    /**
     * Valide le paramètre `resource` (RFC 8707) : absent, ou identique à l'URL du serveur MCP.
     */
    public function validateResource(?string $resource, string $expected): ?string
    {
        if ($resource === null || $resource === '') {
            return null;
        }
        if (rtrim($resource, '/') !== rtrim($expected, '/')) {
            throw new OAuthException('invalid_target', 'Ressource inconnue : seul ' . $expected . ' est servi par ce serveur.');
        }

        return $expected;
    }

    /**
     * Émet un code d'autorisation (clair, renvoyé une seule fois) pour un consentement accordé.
     *
     * @param list<string> $scopes
     */
    public function issueCode(string $clientId, int $userId, string $redirectUri, array $scopes, ?string $resource, string $codeChallenge): string
    {
        $code = self::randomToken();
        $this->repo->storeCode(
            self::hash($code),
            $clientId,
            $userId,
            $redirectUri,
            implode(' ', $scopes),
            $resource,
            $codeChallenge,
            $this->config->authCodeTtl()
        );

        return $code;
    }

    /**
     * Échange code + PKCE contre des jetons (RFC 6749 §4.1.3).
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, string $clientId, string $redirectUri, string $verifier, ?string $resource, string $mcpResource): array
    {
        if ($code === '' || $clientId === '' || $redirectUri === '' || $verifier === '') {
            throw new OAuthException('invalid_request', 'Paramètres requis : code, client_id, redirect_uri, code_verifier.');
        }
        $row = $this->repo->consumeCode(self::hash($code));
        if ($row === null) {
            throw new OAuthException('invalid_grant', 'Code d\'autorisation invalide.');
        }
        if ($row['already_used'] === true) {
            // Réutilisation : révoquer ce que ce code avait produit (RFC 6749 §4.1.2).
            $this->repo->revokeFamily(substr(self::hash($code), 0, 32));
            throw new OAuthException('invalid_grant', 'Code d\'autorisation déjà utilisé.');
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            throw new OAuthException('invalid_grant', 'Code d\'autorisation expiré.');
        }
        if (!hash_equals((string) $row['client_id'], $clientId)) {
            throw new OAuthException('invalid_grant', 'Code émis pour un autre client.');
        }
        if (!hash_equals((string) $row['redirect_uri'], $redirectUri)) {
            throw new OAuthException('invalid_grant', 'redirect_uri différente de celle de l\'autorisation.');
        }
        if (!Pkce::verify($verifier, (string) $row['code_challenge'])) {
            throw new OAuthException('invalid_grant', 'Vérification PKCE échouée (code_verifier).');
        }
        if ($resource !== null && $resource !== '') {
            $this->validateResource($resource, $mcpResource);
        }
        $user = $this->activeUser((int) $row['user_id']);
        $scopes = $this->grantableScopes((string) $row['scope'], $user->role);

        // Famille dérivée du code : permet de révoquer en cas de rejeu du même code.
        $family = substr(self::hash($code), 0, 32);

        return $this->issueTokens($family, $clientId, $user->id, $scopes, $mcpResource);
    }

    /**
     * Rotation du refresh token (RFC 6749 §6 + OAuth 2.1 §4.3.1).
     *
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken, string $clientId, ?string $requestedScope, string $mcpResource): array
    {
        if ($refreshToken === '' || $clientId === '') {
            throw new OAuthException('invalid_request', 'Paramètres requis : refresh_token, client_id.');
        }
        $hash = self::hash($refreshToken);
        $row = $this->repo->findToken($hash);
        if ($row === null || $row['token_type'] !== self::TOKEN_TYPE_REFRESH) {
            throw new OAuthException('invalid_grant', 'Refresh token invalide.');
        }
        if (!hash_equals((string) $row['client_id'], $clientId)) {
            throw new OAuthException('invalid_grant', 'Refresh token émis pour un autre client.');
        }
        if ($row['revoked_at'] !== null) {
            // Réutilisation d'un refresh déjà tourné : vol présumé → toute la famille révoquée.
            $this->repo->revokeFamily((string) $row['family_id']);
            throw new OAuthException('invalid_grant', 'Refresh token déjà utilisé : session révoquée, reconnectez-vous.');
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            throw new OAuthException('invalid_grant', 'Refresh token expiré.');
        }
        if (!$this->repo->revokeToken($hash)) {
            // Course : une autre requête vient de le consommer → traité comme réutilisation.
            $this->repo->revokeFamily((string) $row['family_id']);
            throw new OAuthException('invalid_grant', 'Refresh token déjà utilisé.');
        }
        $user = $this->activeUser((int) $row['user_id'], (string) $row['family_id']);
        if (!$this->credentialsUnchanged($row)) {
            $this->repo->revokeFamily((string) $row['family_id']);
            throw new OAuthException('invalid_grant', 'Mot de passe modifié depuis la connexion : reconnectez-vous.');
        }

        $original = preg_split('/\s+/', (string) $row['scope']) ?: [];
        $scopes = $this->grantableScopes((string) $row['scope'], $user->role);
        if ($requestedScope !== null && trim($requestedScope) !== '') {
            $requested = preg_split('/\s+/', trim($requestedScope)) ?: [];
            if (array_diff($requested, $original) !== []) {
                throw new OAuthException('invalid_scope', 'Un refresh ne peut pas élargir les scopes accordés.');
            }
            $scopes = array_values(array_intersect($scopes, $requested));
            if (!in_array(McpConfig::SCOPE_READ, $scopes, true)) {
                array_unshift($scopes, McpConfig::SCOPE_READ);
            }
        }

        return $this->issueTokens((string) $row['family_id'], $clientId, $user->id, $scopes, $mcpResource, true);
    }

    /**
     * Révocation (RFC 7009) : toujours « succès » vis-à-vis du client.
     */
    public function revoke(string $token, ?string $clientId): void
    {
        if ($token === '') {
            return;
        }
        $row = $this->repo->findToken(self::hash($token));
        if ($row === null) {
            return;
        }
        if ($clientId !== null && $clientId !== '' && !hash_equals((string) $row['client_id'], $clientId)) {
            return;
        }
        if ($row['token_type'] === self::TOKEN_TYPE_REFRESH) {
            $this->repo->revokeFamily((string) $row['family_id']);
        } else {
            $this->repo->revokeToken((string) $row['token_hash']);
        }
    }

    /**
     * Valide un jeton d'accès présenté sur /mcp.
     *
     * @return array{user: User, scopes: list<string>, client_id: string, token_hash: string}|null
     */
    public function validateAccessToken(string $token, string $mcpResource): ?array
    {
        if ($token === '' || strlen($token) > 200) {
            return null;
        }
        $hash = self::hash($token);
        $row = $this->repo->findToken($hash);
        if ($row === null || $row['token_type'] !== self::TOKEN_TYPE_ACCESS || $row['revoked_at'] !== null) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        // Audience OBLIGATOIRE : un jeton sans ressource, ou émis pour une autre, est refusé.
        if ($row['resource'] === null || rtrim((string) $row['resource'], '/') !== rtrim($mcpResource, '/')) {
            return null;
        }
        $user = $this->users->findById((int) $row['user_id']);
        if ($user === null || !$user->isActive) {
            return null;
        }
        if (!$this->credentialsUnchanged($row)) {
            return null;
        }
        $scopes = array_values(array_intersect(
            preg_split('/\s+/', (string) $row['scope']) ?: [],
            $this->grantableScopes((string) $row['scope'], $user->role)
        ));

        return ['user' => $user, 'scopes' => $scopes, 'client_id' => (string) $row['client_id'], 'token_hash' => $hash];
    }

    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    private function issueTokens(string $family, string $clientId, int $userId, array $scopes, string $resource, bool $forceRefresh = false): array
    {
        $access = self::randomToken();
        $ttl = $this->config->accessTokenTtl();
        $scope = implode(' ', $scopes);
        $epoch = $this->users->credentialEpoch($userId);
        $this->repo->storeToken(self::hash($access), self::TOKEN_TYPE_ACCESS, $family, $clientId, $userId, $scope, $resource, $ttl, $epoch);

        $response = [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'scope' => $scope,
        ];
        if ($forceRefresh || in_array(McpConfig::SCOPE_OFFLINE, $scopes, true)) {
            $refresh = self::randomToken();
            $this->repo->storeToken(
                self::hash($refresh),
                self::TOKEN_TYPE_REFRESH,
                $family,
                $clientId,
                $userId,
                $scope,
                $resource,
                $this->config->refreshTokenTtl(),
                $epoch
            );
            $response['refresh_token'] = $refresh;
        }
        if (!ClientResolver::isUrlClientId($clientId)) {
            $this->repo->touchClient($clientId);
        }

        return $response;
    }

    /**
     * Vrai si le mot de passe de l'utilisateur n'a pas changé depuis l'émission du jeton
     * (empreinte `credential_epoch`). Un jeton sans empreinte est refusé.
     *
     * @param array<string, mixed> $row
     */
    private function credentialsUnchanged(array $row): bool
    {
        $stored = $row['credential_epoch'] ?? null;
        $current = $this->users->credentialEpoch((int) $row['user_id']);

        return is_string($stored) && $current !== null && hash_equals($current, $stored);
    }

    private function activeUser(int $userId, ?string $familyToRevoke = null): User
    {
        $user = $this->users->findById($userId);
        if ($user === null || !$user->isActive) {
            if ($familyToRevoke !== null) {
                $this->repo->revokeFamily($familyToRevoke);
            }
            throw new OAuthException('invalid_grant', 'Compte utilisateur inexistant ou désactivé.');
        }

        return $user;
    }
}
