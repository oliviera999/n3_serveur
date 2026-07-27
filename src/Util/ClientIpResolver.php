<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Résolution de l'IP cliente réellement fiable, pour les clés de limitation de débit.
 *
 * `X-Forwarded-For` est un en-tête **fourni par le client** : n'importe qui peut le
 * poser et le faire varier. Utilisé sans condition, il rend le rate-limit inopérant —
 * un attaquant obtient un compteur neuf à chaque requête — et permet d'empoisonner le
 * compteur d'une IP tierce. On ne lui fait donc confiance que si la connexion vient
 * réellement d'un proxy connu : `REMOTE_ADDR` (posé par le serveur web, non
 * falsifiable au niveau applicatif) doit appartenir à `TRUSTED_PROXIES`.
 *
 * `TRUSTED_PROXIES` : liste CSV d'adresses IP exactes et/ou de plages CIDR, IPv4 comme
 * IPv6. Vide ou absent = aucun proxy de confiance, `X-Forwarded-For` totalement ignoré
 * — le défaut sûr, valable en hébergement direct.
 *
 * ── Origine (6.34.0) ────────────────────────────────────────────────────────────
 * Cette logique existait déjà, correcte, dans `RateLimitMiddleware::clientIp()`
 * (durcissement « S1 » du limiteur de login). Elle est **extraite ici verbatim** parce
 * que les limiteurs firmware — `AbstractPostDataController::enforceFirmwareRateLimit()`
 * et `LegacyHeartbeatHandler::handle()` — en avaient une copie naïve qui faisait
 * confiance à `X-Forwarded-For` sans condition. Extraire plutôt que réécrire évite
 * d'introduire une troisième variante, et fait profiter les deux appelants firmware du
 * support IPv6 (`inet_pton`) que la version d'origine avait déjà.
 */
final class ClientIpResolver
{
    public const UNKNOWN = 'unknown';

    /**
     * IP cliente à utiliser comme clé de limitation.
     */
    public static function resolve(Request $request): string
    {
        $server = $request->getServerParams();
        $remote = $server['REMOTE_ADDR'] ?? '';
        $remote = is_string($remote) ? $remote : '';

        if ($remote !== '' && self::isTrustedProxy($remote)) {
            // Derrière un proxy de confiance : la première entrée de la chaîne est le
            // client d'origine (les suivantes sont les proxys traversés).
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }

        return $remote !== '' ? $remote : self::UNKNOWN;
    }

    /**
     * Vrai si l'IP appartient à la liste des proxys de confiance
     * (`TRUSTED_PROXIES`, CSV d'IP ou de plages CIDR IPv4/IPv6).
     */
    public static function isTrustedProxy(string $ip): bool
    {
        $raw = $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES');
        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if (self::ipInCidr($ip, $entry)) {
                    return true;
                }
                continue;
            }
            // Comparaison binaire : `::1` et `0:0:0:0:0:0:0:1` désignent la même adresse.
            if (inet_pton($entry) !== false && inet_pton($entry) === inet_pton($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Teste l'appartenance d'une IP (v4/v6) à une plage CIDR.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $maskLen] = array_pad(explode('/', $cidr, 2), 2, '');
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        // Familles d'adresses différentes (IPv4 vs IPv6) : pas de correspondance.
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bits = (int) $maskLen;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
