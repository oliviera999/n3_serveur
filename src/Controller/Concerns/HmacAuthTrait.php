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
 * Configurable via supervision (BDD `serverSettings`) ou .env :
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
        //
        // REPLI (6.31.0) : une signature X-Sig-* qui NE VALIDE PAS ne rejette plus
        // par elle-meme (sauf HMAC_STRICT_MODE) — on poursuit sur le contrat legacy
        // (timestamp+signature dans le corps) puis sur api_key. Motif : sous mod_php
        // + x-www-form-urlencoded, php://input est vide (cf. CHANGELOG 5.1.12), donc
        // le corps signe reconstitue cote serveur peut etre absent alors que le
        // firmware, lui, a bien signe son corps -> 401 sur des mesures parfaitement
        // authentiques. Seul FFP3 dispose d'une reconstitution canonique
        // (App\Security\Ffp3HmacPostBody) ; N3PP/MSP1/PGL n'en ont pas.
        // Meme politique additive que App\Security\DeviceSignatureValidator (galerie).
        if (isset($params['__sig_hmac']) && is_string($params['__sig_hmac']) && $params['__sig_hmac'] !== '') {
            $sigSecret = $this->hmacSecret();
            if (!is_string($sigSecret) || $sigSecret === '') {
                $this->logger->error("{$this->componentName()}: rejet config API_SIG_SECRET manquante code=500");
                return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
            }
            $sigWindow = $this->opInt('SIG_VALID_WINDOW', 300);
            if ($sigWindow <= 0) {
                $sigWindow = 300;
            }
            $bodyTs = (string) ($params['__sig_ts'] ?? '');
            $bodyNonce = (string) ($params['__sig_nonce'] ?? '');
            $bodyRaw = (string) ($params['__sig_body'] ?? '');
            // Journalise d'ou vient le corps signe : c'est la seule facon de
            // savoir, depuis la production, si `php://input` est reellement vide
            // sur cet hebergement (hypothese jamais verifiee du 5.1.12) ou si la
            // machinerie de reconstitution est devenue inutile.
            // Cf. App\Util\SignedBodyResolver.
            $bodySource = (string) ($params['__sig_body_source'] ?? 'unknown');
            if (SignatureValidator::isValidForBody(
                $bodyTs,
                $bodyNonce,
                $bodyRaw,
                (string) $params['__sig_hmac'],
                $sigSecret,
                $sigWindow
            )) {
                $this->authenticatedByHmac = true;
                $this->logger->info("{$this->componentName()}: auth HMAC body OK", [
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'body_source' => $bodySource,
                ]);
                $this->recordHmacAudit('ok', 'x_sig_body', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'version' => trim((string) ($params['version'] ?? '')),
                    'ts_received' => $bodyTs,
                    'window_s' => $sigWindow,
                    'body_len' => strlen($bodyRaw),
                    'body_source' => $bodySource,
                ]);

                return null;
            }

            $strictBody = $this->isHmacStrictMode();
            $this->logger->warning(
                $strictBody
                    ? "{$this->componentName()}: rejet auth HMAC body invalide code=401"
                    : "{$this->componentName()}: auth HMAC body invalide, repli contrat legacy/api_key",
                [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'ts_received' => $bodyTs,
                    'window_s' => $sigWindow,
                    'body_len' => strlen($bodyRaw),
                    'body_source' => $bodySource,
                    'strict' => $strictBody,
                ]
            );
            $this->recordHmacAudit('reject', 'x_sig_body', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'sensor' => trim((string) ($params['sensor'] ?? '')),
                'version' => trim((string) ($params['version'] ?? '')),
                'ts_received' => $bodyTs,
                'window_s' => $sigWindow,
                'body_len' => strlen($bodyRaw),
                'body_source' => $bodySource,
            ], $strictBody ? 'signature_invalid' : 'signature_invalid_soft_fallback');

            if ($strictBody) {
                return ResponseHelper::text($response, 'Signature incorrecte', 401);
            }
            // Repli : on NE retourne pas — le contrat legacy ci-dessous, puis
            // api_key (authenticatedByHmac reste false), restent a satisfaire.
        }

        $timestamp = $params['timestamp'] ?? null;
        $signature = $params['signature'] ?? null;
        $strict = $this->isHmacStrictMode();
        $nonceRequired = $this->isHmacNonceRequired();

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
                $this->recordHmacAudit('reject', 'absent', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'version' => trim((string) ($params['version'] ?? '')),
                ], 'strict_mode');
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
            $this->recordHmacAudit('reject', 'legacy_body', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'sensor' => trim((string) ($params['sensor'] ?? '')),
                'version' => trim((string) ($params['version'] ?? '')),
                'has_timestamp' => $timestamp !== null ? '1' : '0',
                'has_signature' => $signature !== null ? '1' : '0',
            ], 'signature_incomplete');
            return ResponseHelper::text($response, 'Signature incomplete', 401);
        }

        $sigSecret = $this->hmacSecret();
        if (!is_string($sigSecret) || $sigSecret === '') {
            $this->logger->error(
                "{$this->componentName()}: rejet config API_SIG_SECRET manquante code=500"
            );
            return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
        }

        $sigWindow = $this->opInt('SIG_VALID_WINDOW', 300);
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
            $this->recordHmacAudit('reject', $nonceRequired ? 'legacy_nonce' : 'legacy_timestamp', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'sensor' => trim((string) ($params['sensor'] ?? '')),
                'version' => trim((string) ($params['version'] ?? '')),
                'ts_received' => (string) $timestamp,
                'window_s' => $sigWindow,
                'post_id' => $postId !== '' ? $postId : null,
            ], 'signature_invalid');
            return ResponseHelper::text($response, 'Signature incorrecte', 401);
        }

        $this->authenticatedByHmac = true;
        $this->logger->info("{$this->componentName()}: auth HMAC OK", [
            'sensor' => trim((string) ($params['sensor'] ?? '')),
        ]);
        $this->recordHmacAudit('ok', $nonceRequired ? 'legacy_nonce' : 'legacy_timestamp', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            'sensor' => trim((string) ($params['sensor'] ?? '')),
            'version' => trim((string) ($params['version'] ?? '')),
            'ts_received' => (string) $timestamp,
            'window_s' => $sigWindow,
            'post_id' => $postId !== '' ? $postId : null,
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
