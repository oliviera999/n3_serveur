<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * PKCE (RFC 7636) — méthode S256 uniquement (la méthode `plain` est refusée).
 */
final class Pkce
{
    /** code_challenge S256 = BASE64URL(SHA256(verifier)) : 43 caractères. */
    private const CHALLENGE_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    /** code_verifier : 43 à 128 caractères non réservés (RFC 7636 §4.1). */
    private const VERIFIER_PATTERN = '/^[A-Za-z0-9._~-]{43,128}$/';

    public static function isValidChallenge(string $challenge): bool
    {
        return preg_match(self::CHALLENGE_PATTERN, $challenge) === 1;
    }

    public static function isValidVerifier(string $verifier): bool
    {
        return preg_match(self::VERIFIER_PATTERN, $verifier) === 1;
    }

    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function verify(string $verifier, string $challenge): bool
    {
        return self::isValidVerifier($verifier) && hash_equals($challenge, self::challengeFor($verifier));
    }
}
