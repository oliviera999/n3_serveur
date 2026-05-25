<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Vérifie l'authenticité des requêtes entrantes par signature HMAC.
 *
 * Contrats supportés :
 *   - Legacy (FFP5CS actuel) : HMAC-SHA256(timestamp, secret) — pas de nonce.
 *     Le replay est borné par la fenêtre temporelle (par défaut 300 s).
 *   - Avec nonce legacy : HMAC-SHA256(timestamp . "|" . nonce, secret).
 *     Le serveur peut en plus déduplicquer le nonce (ex. post_id) côté repository
 *     pour empêcher le replay strict dans la fenêtre.
 *   - En-têtes FFP5CS v13.80+ : HMAC-SHA256(timestamp . "\n" . nonce . "\n" . body, secret)
 *     avec X-Sig-Timestamp, X-Sig-Nonce et X-Sig-Hmac.
 *
 * Le client (firmware) et le serveur DOIVENT utiliser la même convention. La fonction
 * `createSignatureWithNonce()` documente le format canonique (`<timestamp>|<nonce>`).
 */
class SignatureValidator
{
    /**
     * Génère la signature HMAC sans nonce (compat firmware actuel).
     */
    public static function createSignature(int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', (string) $timestamp, $secret);
    }

    /**
     * Génère la signature HMAC avec nonce. Le message canonique est `<timestamp>|<nonce>`.
     */
    public static function createSignatureWithNonce(int $timestamp, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '|' . $nonce, $secret);
    }

    /**
     * Génère la signature HMAC utilisée par le firmware FFP5CS v13.80+.
     */
    public static function createSignatureForBody(int $timestamp, string $nonce, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);
    }

    /**
     * Valide la signature fournie (sans nonce). Fenêtre temporelle pour limiter le replay.
     *
     * @param string $timestamp Timestamp UNIX reçu (en secondes)
     * @param string $signature Signature HMAC reçue (hex)
     * @param string $secret    Secret partagé
     * @param int    $window    Fenêtre temporelle autorisée (s)
     */
    public static function isValid(string $timestamp, string $signature, string $secret, int $window = 300): bool
    {
        if (!ctype_digit($timestamp)) {
            return false;
        }

        $ts = (int) $timestamp;

        if (abs(time() - $ts) > $window) {
            return false;
        }

        $expected = self::createSignature($ts, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Valide la signature fournie (avec nonce). Recommandée : combinée à une déduplication
     * serveur du nonce (ex. table `ffp3Data.post_id UNIQUE`), elle empêche tout replay strict.
     *
     * @param string $timestamp Timestamp UNIX reçu (en secondes)
     * @param string $nonce     Nonce (post_id, uuid, etc.) — non vide
     * @param string $signature Signature HMAC reçue (hex)
     * @param string $secret    Secret partagé
     * @param int    $window    Fenêtre temporelle autorisée (s)
     */
    public static function isValidWithNonce(
        string $timestamp,
        string $nonce,
        string $signature,
        string $secret,
        int $window = 300
    ): bool {
        if (!ctype_digit($timestamp) || $nonce === '') {
            return false;
        }
        $ts = (int) $timestamp;
        if (abs(time() - $ts) > $window) {
            return false;
        }
        $expected = self::createSignatureWithNonce($ts, $nonce, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Valide les en-têtes X-Sig-* du firmware FFP5CS v13.80+.
     */
    public static function isValidForBody(
        string $timestamp,
        string $nonce,
        string $body,
        string $signature,
        string $secret,
        int $window = 300
    ): bool {
        if (!ctype_digit($timestamp) || $nonce === '') {
            return false;
        }
        $ts = (int) $timestamp;
        if (abs(time() - $ts) > $window) {
            return false;
        }
        $expected = self::createSignatureForBody($ts, $nonce, $body, $secret);
        return hash_equals($expected, $signature);
    }
}
