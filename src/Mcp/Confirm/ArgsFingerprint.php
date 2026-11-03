<?php

declare(strict_types=1);

namespace App\Mcp\Confirm;

/**
 * Empreinte sha256 du JSON canonique des arguments (clés triées), hors champs de confirmation.
 */
final class ArgsFingerprint
{
    private const EXCLUDE = ['confirmation_id', 'confirmation_code'];

    /**
     * @param array<string, mixed> $arguments
     */
    public static function of(array $arguments): string
    {
        $filtered = [];
        foreach ($arguments as $key => $value) {
            if (in_array((string) $key, self::EXCLUDE, true)) {
                continue;
            }
            $filtered[(string) $key] = $value;
        }
        ksort($filtered);

        return hash('sha256', (string) json_encode(
            $filtered,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }
}
