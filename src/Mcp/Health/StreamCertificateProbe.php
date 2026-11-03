<?php

declare(strict_types=1);

namespace App\Mcp\Health;

/**
 * Sonde TLS par `stream_socket_client()` + `openssl_x509_parse()`, délai court (3 s).
 *
 * La vérification de la chaîne est volontairement désactivée : le but est de LIRE la date
 * d'expiration, y compris d'un certificat déjà expiré (que la vérification refuserait). Aucune
 * donnée n'est envoyée à l'hôte au-delà de la poignée de main TLS (SNI = l'hôte demandé).
 * Toute erreur (DNS, pare-feu, délai, absence d'OpenSSL) est rendue dans `error`, jamais levée.
 */
final class StreamCertificateProbe implements CertificateProbe
{
    public function __construct(private float $timeoutSeconds = 3.0)
    {
    }

    public function probe(string $host, int $port = 443): array
    {
        if (preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            return ['ok' => false, 'host' => $host, 'error' => 'Nom d\'hôte invalide.'];
        }
        if (!function_exists('openssl_x509_parse') || !function_exists('stream_socket_client')) {
            return ['ok' => false, 'host' => $host, 'error' => 'Extension OpenSSL indisponible.'];
        }
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('ssl://%s:%d', $host, $port),
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            return ['ok' => false, 'host' => $host, 'error' => sprintf('Connexion TLS impossible (%d) %s', $errno, mb_substr($errstr, 0, 120))];
        }
        try {
            $params = stream_context_get_params($socket);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($cert === null) {
                return ['ok' => false, 'host' => $host, 'error' => 'Certificat non transmis par le serveur.'];
            }
            $parsed = @openssl_x509_parse($cert);
            if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
                return ['ok' => false, 'host' => $host, 'error' => 'Certificat illisible.'];
            }

            return self::describe($host, $parsed);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param array<string, mixed> $parsed Résultat d'openssl_x509_parse()
     * @return array{ok: bool, host: string, expires_at: string, days_remaining: int, issuer: string|null, subject: string|null}
     */
    public static function describe(string $host, array $parsed, ?int $now = null): array
    {
        $validTo = (int) $parsed['validTo_time_t'];
        $issuer = is_array($parsed['issuer'] ?? null) ? ($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null) : null;
        $subject = is_array($parsed['subject'] ?? null) ? ($parsed['subject']['CN'] ?? null) : null;

        return [
            'ok' => true,
            'host' => $host,
            'expires_at' => date('Y-m-d H:i:s', $validTo),
            'days_remaining' => (int) floor(($validTo - ($now ?? time())) / 86400),
            'issuer' => is_string($issuer) ? $issuer : null,
            'subject' => is_string($subject) ? $subject : null,
        ];
    }
}
