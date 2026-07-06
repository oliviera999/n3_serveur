<?php

declare(strict_types=1);

namespace App\Controller\Concerns;

use App\Security\SignatureValidator;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Logique d'authentification HMAC-SHA256 optionnelle pour les controllers
 * legacy (Msp, N3pp) qui acceptent aussi la clé API simple.
 *
 * Contrat (aligné sur FFP3) :
 *   - Si le POST contient `timestamp` ET `signature`, on valide HMAC :
 *     signature == HMAC-SHA256(timestamp, API_SIG_SECRET)
 *     avec fenêtre temporelle SIG_VALID_WINDOW (s).
 *   - Si un seul des deux est présent : rejet 401.
 *   - Si aucun n'est présent : fallback sur la clé API (gérée par
 *     AbstractPostDataController::handle()).
 *
 * Les controllers utilisateurs doivent :
 *   1. `use HmacAuthTrait;` dans la classe.
 *   2. Surcharger `validateAuth()` pour appeler `$this->validateHmacOrFallback()`.
 *   3. Disposer d'un `LogService $logger` (déjà fourni par AbstractPostDataController).
 *
 * Variables d'environnement :
 *   - `API_SIG_SECRET` : secret partagé HMAC.
 *   - `SIG_VALID_WINDOW` : fenêtre de validité timestamp (défaut 300 s).
 */
trait HmacAuthTrait
{
    /**
     * Valide la signature HMAC si présente. Active `authenticatedByHmac` en cas
     * de succès pour bypasser la vérification api_key.
     *
     * Configurable via .env :
     *   - HMAC_STRICT_MODE=true    : refuse l'absence de HMAC (au lieu de fallback api_key).
     *   - HMAC_NONCE_REQUIRED=true : exige post_id et utilise isValidWithNonce.
     *
     * @param array<string, mixed> $params Paramètres POST déjà extraits.
     * @return Response|null null si OK ou si HMAC absent (fallback api_key) ;
     *                        Response 401/500 en cas de rejet.
     */
    protected function validateHmacOrFallback(array $params, Response $response): ?Response
    {
        // Body-signing (en-tetes X-Sig-*, exposes par prepareParamsForAuth) :
        // PRIORITAIRE si present. Signe le corps COMPLET via isValidForBody
        // (HMAC(ts . "\n" . nonce . "\n" . body)) -> integrite du corps + fenetre
        // temps, contrairement au contrat legacy qui ne signe que le timestamp.
        // Additif : absent -> on continue sur le contrat existant ci-dessous.
        if (isset($params['__sig_hmac']) && is_string($params['__sig_hmac']) && $params['__sig_hmac'] !== '') {
            $sigSecret = $this->hmacSecret();
            if (!is_string($sigSecret) || $sigSecret === '') {
                $this->logger->error("{$this->componentName()}: rejet config API_SIG_SECRET manquante code=500");
                return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
            }
            $sigWindow = (int) ($_ENV['SIG_VALID_WINDOW'] ?? 300);
            if ($sigWindow <= 0) {
                $sigWindow = 300;
            }
            $bodyTs = (string) ($params['__sig_ts'] ?? '');
            $bodyNonce = (string) ($params['__sig_nonce'] ?? '');
            $bodyRaw = (string) ($params['__sig_body'] ?? '');
            if (!SignatureValidator::isValidForBody(
                $bodyTs,
                $bodyNonce,
                $bodyRaw,
                (string) $params['__sig_hmac'],
                $sigSecret,
                $sigWindow
            )) {
                $this->logger->warning("{$this->componentName()}: rejet auth HMAC body invalide code=401", [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'ts_received' => $bodyTs,
                    'window_s' => $sigWindow,
                ]);
                return ResponseHelper::text($response, 'Signature incorrecte', 401);
            }
            $this->authenticatedByHmac = true;
            $this->logger->info("{$this->componentName()}: auth HMAC body OK", [
                'sensor' => trim((string) ($params['sensor'] ?? '')),
            ]);

            return null;
        }

        $timestamp = $params['timestamp'] ?? null;
        $signature = $params['signature'] ?? null;
        $strict = filter_var($_ENV['HMAC_STRICT_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $nonceRequired = filter_var($_ENV['HMAC_NONCE_REQUIRED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        if ($timestamp === null && $signature === null) {
            if ($strict) {
                $this->logger->warning(
                    "{$this->componentName()}: rejet auth HMAC absent (strict) code=401",
                    [
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                        'sensor' => trim((string) ($params['sensor'] ?? '')),
                        'version' => trim((string) ($params['version'] ?? '')),
                    ]
                );
                return ResponseHelper::text($response, 'Signature HMAC requise (strict mode)', 401);
            }
            // Compat : fallback sur api_key (logique parent).
            return null;
        }

        if ($timestamp === null || $signature === null) {
            $this->logger->warning(
                "{$this->componentName()}: rejet auth signature incomplete code=401",
                [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'version' => trim((string) ($params['version'] ?? '')),
                    'has_timestamp' => $timestamp !== null,
                    'has_signature' => $signature !== null,
                ]
            );
            return ResponseHelper::text($response, 'Signature incomplete', 401);
        }

        $sigSecret = $this->hmacSecret();
        if (!is_string($sigSecret) || $sigSecret === '') {
            $this->logger->error(
                "{$this->componentName()}: rejet config API_SIG_SECRET manquante code=500"
            );
            return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
        }

        $sigWindow = (int) ($_ENV['SIG_VALID_WINDOW'] ?? 300);
        if ($sigWindow <= 0) {
            $sigWindow = 300;
        }

        $postId = isset($params['post_id']) && is_scalar($params['post_id'])
            ? substr(trim((string) $params['post_id']), 0, 64) : '';

        if ($nonceRequired) {
            if ($postId === '') {
                $this->logger->warning(
                    "{$this->componentName()}: rejet auth HMAC sans post_id (nonce requis) code=401",
                    [
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                        'sensor' => trim((string) ($params['sensor'] ?? '')),
                    ]
                );
                return ResponseHelper::text($response, 'post_id requis (nonce HMAC)', 401);
            }
            $valid = SignatureValidator::isValidWithNonce(
                (string) $timestamp,
                $postId,
                (string) $signature,
                $sigSecret,
                $sigWindow
            );
        } else {
            $valid = SignatureValidator::isValid(
                (string) $timestamp,
                (string) $signature,
                $sigSecret,
                $sigWindow
            );
        }

        if (!$valid) {
            $this->logger->warning(
                "{$this->componentName()}: rejet auth HMAC invalide code=401",
                [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'version' => trim((string) ($params['version'] ?? '')),
                    'ts_received' => (string) $timestamp,
                    'window_s' => $sigWindow,
                    'nonce_required' => $nonceRequired,
                ]
            );
            return ResponseHelper::text($response, 'Signature incorrecte', 401);
        }

        $this->authenticatedByHmac = true;
        $this->logger->info("{$this->componentName()}: auth HMAC OK", [
            'sensor' => trim((string) ($params['sensor'] ?? '')),
        ]);

        return null;
    }

    /**
     * Secret HMAC partagé firmware <-> serveur. Par défaut la clé commune
     * `API_SIG_SECRET` (contrat FFP3/N3PP/MSP). Un controller peut surcharger
     * pour accepter une clé dédiée (ex. PGL : `PGL_API_SIG_SECRET` avec repli
     * `API_SIG_SECRET`). Une valeur vide/absente déclenche le rejet 500 côté
     * appelant quand le firmware envoie une signature.
     */
    protected function hmacSecret(): ?string
    {
        $secret = $_ENV['API_SIG_SECRET'] ?? null;

        return is_string($secret) ? $secret : null;
    }
}
