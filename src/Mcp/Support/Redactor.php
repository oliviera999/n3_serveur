<?php

declare(strict_types=1);

namespace App\Mcp\Support;

/**
 * Masque les secrets potentiels dans un texte libre (messages d'erreur, sujets d'alerte)
 * avant de le renvoyer au modèle : adresses e-mail, jetons/condensats longs, paramètres
 * sensibles (`password=`, `api_key=`, `token=`, `secret=`, `Authorization: …`).
 */
final class Redactor
{
    private const PATTERNS = [
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[email masqué]',
        '/\b(authorization|bearer)\b\s*[:=]?\s*\S+/i' => '$1 [masqué]',
        '/\b(pass(word)?|pwd|api[_-]?key|token|secret|sig(nature)?|hmac|cookie)\b(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i' => '$1$4[masqué]',
        '/\b[A-Za-z0-9_\-]{32,}\b/' => '[valeur longue masquée]',
        '/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}/' => '[hash masqué]',
    ];

    public static function redact(?string $text, int $maxLength = 300): ?string
    {
        if ($text === null) {
            return null;
        }
        $out = $text;
        foreach (self::PATTERNS as $pattern => $replacement) {
            $out = (string) preg_replace($pattern, $replacement, $out);
        }
        if (mb_strlen($out) > $maxLength) {
            $out = mb_substr($out, 0, $maxLength) . '…';
        }

        return $out;
    }
}
