<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Clé HMAC de l'« époque d'identifiants » des jetons MCP
 * ({@see \App\Repository\UserRepository::credentialEpoch()}).
 *
 * L'époque est une empreinte du hachage de mot de passe liée à chaque jeton : elle change
 * quand le mot de passe change et invalide alors les jetons émis avant. Sa clé est dérivée
 * d'un secret serveur pour qu'une fuite de la base (hachages + époques) ne suffise pas à
 * relier les deux :
 *
 *   1. `MCP_CREDENTIAL_SECRET` (dédié, recommandé : sa rotation n'impacte pas les firmwares) ;
 *   2. sinon `API_SIG_SECRET` (secret HMAC des ESP32, déjà présent en production) ;
 *   3. sinon repli sur une clé fixe, signalé par {@see isFallback()} et journalisé par
 *      l'appelant (`OAuthController`) — le connecteur reste fonctionnel.
 *
 * ⚠️ Changer le secret effectif (rotation, ou passage de 2 à 1) change toutes les époques :
 * toutes les connexions MCP sont alors refusées et chaque utilisateur doit se reconnecter
 * une fois depuis Claude. Aucune autre donnée n'est affectée.
 */
final class McpCredentialEpoch
{
    private const LABEL = 'mcp-credential-epoch';

    public static function key(): string
    {
        $secret = self::secret();

        return $secret === null ? self::LABEL : hash_hmac('sha256', self::LABEL, $secret);
    }

    public static function isFallback(): bool
    {
        return self::secret() === null;
    }

    public static function compute(string $passwordHash): string
    {
        return substr(hash_hmac('sha256', $passwordHash, self::key()), 0, 16);
    }

    private static function secret(): ?string
    {
        foreach (['MCP_CREDENTIAL_SECRET', 'API_SIG_SECRET'] as $name) {
            $raw = $_ENV[$name] ?? getenv($name);
            if (is_string($raw) && trim($raw) !== '') {
                return trim($raw);
            }
        }

        return null;
    }
}
