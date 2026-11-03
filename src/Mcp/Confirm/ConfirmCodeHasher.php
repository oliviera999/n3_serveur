<?php

declare(strict_types=1);

namespace App\Mcp\Confirm;

use App\Security\McpCredentialEpoch;

/**
 * HMAC du code à 6 chiffres (jamais stocké en clair).
 * Clé dérivée comme {@see McpCredentialEpoch} avec le libellé `mcp-confirm-code`.
 */
final class ConfirmCodeHasher
{
    private const LABEL = 'mcp-confirm-code';

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, self::key());
    }

    public static function verify(string $code, string $hmac): bool
    {
        return hash_equals($hmac, self::hash($code));
    }

    private static function key(): string
    {
        // Réutilise la même source de secret que l'époque des jetons.
        $epochKey = McpCredentialEpoch::key();

        return hash_hmac('sha256', self::LABEL, $epochKey);
    }
}
