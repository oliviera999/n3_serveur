<?php

declare(strict_types=1);

namespace App\Notification;

use App\Service\LogService;

/**
 * Fabrique le transport e-mail adapté à la configuration courante.
 *
 * Priorité :
 *   1. SMTP via symfony/mailer si un DSN est résolvable depuis l'environnement
 *      (SMTP_DSN explicite, ou reconstruit depuis SMTP_HOST/SMTP_PORT/…) ;
 *   2. sinon, repli gracieux sur la fonction PHP mail() ({@see NativeMailTransport})
 *      pour que dev / CI / hôtes sans SMTP continuent de fonctionner.
 *
 * Le DSN n'est jamais loggé (il peut contenir des identifiants SMTP).
 */
final class MailTransportFactory
{
    /**
     * @param array<string, mixed>|null $env Source de configuration (défaut : $_ENV)
     */
    public static function fromEnv(LogService $logger, ?array $env = null): MailTransport
    {
        $env ??= $_ENV;

        $dsn = self::resolveDsn($env);
        if ($dsn !== null) {
            return new SymfonyMailTransport($dsn, $logger);
        }

        return new NativeMailTransport();
    }

    /**
     * Résout un DSN SMTP depuis l'environnement, ou null si non configuré.
     *
     * @param array<string, mixed> $env
     */
    public static function resolveDsn(array $env): ?string
    {
        $explicit = self::str($env['SMTP_DSN'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $host = self::str($env['SMTP_HOST'] ?? null);
        if ($host === null) {
            return null;
        }

        $scheme = 'smtp';
        $port = self::str($env['SMTP_PORT'] ?? null);
        $user = self::str($env['SMTP_USER'] ?? null);
        $pass = self::str($env['SMTP_PASS'] ?? null);
        $encryption = strtolower((string) ($env['SMTP_ENCRYPTION'] ?? ''));

        // « smtps » force TLS implicite (port 465). « tls »/« ssl » : équivalent ici.
        if (in_array($encryption, ['ssl', 'tls', 'smtps'], true)) {
            $scheme = 'smtps';
        }

        $credentials = '';
        if ($user !== null) {
            $credentials = rawurlencode($user);
            if ($pass !== null) {
                $credentials .= ':' . rawurlencode($pass);
            }
            $credentials .= '@';
        }

        $authority = $host;
        if ($port !== null) {
            $authority .= ':' . $port;
        }

        return $scheme . '://' . $credentials . $authority;
    }

    /** Normalise une valeur d'env en chaîne non vide, ou null. */
    private static function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
