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
 * ADDITIF / RÉTRO-COMPATIBLE : si les en-têtes sont absents, ou si le serveur n'a pas de
 * API_SIG_SECRET configuré, on retourne null => l'appelant retombe sur l'auth par clé API. La
 * signature n'est donc JAMAIS obligatoire ; elle renforce l'authenticité quand elle est présente.
 */
final class DeviceSignatureValidator
{
    /**
     * @return bool|null null = pas de signature présente (fallback clé API) ; true = signature valide ;
     *                   false = signature présente mais invalide (l'appelant doit rejeter).
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

        return SignatureValidator::isValidForBody($timestamp, $nonce, $signedBody, $signature, $secret, $window);
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
