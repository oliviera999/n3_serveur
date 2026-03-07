<?php

namespace App\Security;

/**
 * Vérifie l'authenticité des requêtes entrantes par signature HMAC.
 * - La signature est calculée : HMAC-SHA256(timestamp, secret)
 * - Un horizon temporel limite le risque de rejeu.
 */
class SignatureValidator
{
    /**
     * Génère la signature côté client/serveur.
     */
    public static function createSignature(int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', (string) $timestamp, $secret);
    }

    /**
     * Valide la signature fournie.
     *
     * @param string $timestamp Timestamp UNIX reçu (en secondes)
     * @param string $signature Signature HMAC reçue
     * @param string $secret    Secret partagé
     * @param int    $window    Fenêtre temporelle autorisée (s)
     */
    public static function isValid(string $timestamp, string $signature, string $secret, int $window = 300): bool
    {
        // Timestamp numérique ?
        if (!ctype_digit($timestamp)) {
            return false;
        }

        $ts = (int) $timestamp;

        // Fenêtre de validité
        if (abs(time() - $ts) > $window) {
            return false;
        }

        $expected = self::createSignature($ts, $secret);
        return hash_equals($expected, $signature);
    }
} 