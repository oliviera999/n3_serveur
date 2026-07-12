<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Env;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Validation de la signature HMAC-SHA256 additive des requêtes firmware sur les endpoints galerie
 * (uploadphotosserver : upload multipart, sync start/finish, POST version).
 *
 * Contrat (aligné sur SignatureValidator::isValidForBody et sur shared/n3_data / camera_uploader) :
 *   en-têtes X-Sig-Timestamp, X-Sig-Nonce, X-Sig-Hmac avec
 *   hmac = HMAC-SHA256(timestamp . "\n" . nonce . "\n" . <corps signé>, API_SIG_SECRET).
 *
 * Le « corps signé » dépend de l'endpoint :
 *   - upload multipart : condensé stable = la clé API (le corps JPEG n'est pas signable en streaming) ;
 *   - sync / version (form-urlencoded via n3_data) : le corps brut de la requête.
 *
 * Comportement (v6.22.2+) :
 *   - en-têtes absents, ou API_SIG_SECRET serveur vide → null (fallback clé API) ;
 *   - signature valide → true ;
 *   - signature invalide → null (fallback clé API) sauf si GALLERY_HMAC_STRICT=true → false (rejet).
 *
 * Ainsi le HMAC est optionnel : une horloge firmware hors fenêtre ou un condensé faux ne bloque
 * plus l'auth tant que la clé API est bonne. Le mode strict restaure l'ancien rejet 401.
 */
final class DeviceSignatureValidator
{
    /**
     * @return bool|null null = pas de signature / ignore / soft-fail (fallback clé API) ;
     *                   true = signature valide ;
     *                   false = signature invalide en mode strict (l'appelant doit rejeter).
     */
    public static function verify(Request $request, string $signedBody): ?bool
    {
        $signature = trim($request->getHeaderLine('X-Sig-Hmac'));
        if ($signature === '') {
            return null;
        }

        Env::load();
        $secret = trim((string) ($_ENV['API_SIG_SECRET'] ?? ''));
        if ($secret === '') {
            // Serveur non configuré pour le HMAC : on ignore la signature (rétro-compatibilité totale).
            return null;
        }

        $timestamp = trim($request->getHeaderLine('X-Sig-Timestamp'));
        $nonce = trim($request->getHeaderLine('X-Sig-Nonce'));
        $window = (int) ($_ENV['SIG_VALID_WINDOW'] ?? 300);
        if ($window <= 0) {
            $window = 300;
        }

        $valid = SignatureValidator::isValidForBody(
            $timestamp,
            $nonce,
            $signedBody,
            $signature,
            $secret,
            $window
        );
        if ($valid) {
            return true;
        }

        // HMAC optionnel : invalide → fallback api_key, sauf mode strict galerie.
        if (self::isStrict()) {
            return false;
        }

        return null;
    }

    /**
     * Mode strict : une signature X-Sig-* présente mais invalide provoque un rejet (401).
     * Défaut false — HMAC additif avec fallback clé API.
     */
    public static function isStrict(): bool
    {
        Env::load();
        $raw = strtolower(trim((string) ($_ENV['GALLERY_HMAC_STRICT'] ?? 'false')));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Corps brut de la requête (form-urlencoded) capturé par RawPostBodyMiddleware, avec repli sur le
     * flux du corps PSR-7. Utilisé comme « corps signé » pour les endpoints sync / version.
     */
    public static function rawBody(Request $request): string
    {
        $raw = $request->getAttribute(\App\Middleware\RawPostBodyMiddleware::ATTRIBUTE);
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        return (string) $request->getBody();
    }
}
