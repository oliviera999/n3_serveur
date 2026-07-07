<?php

declare(strict_types=1);

namespace App\Controller\Concerns;

use App\Middleware\RawPostBodyMiddleware;
use App\Security\RateLimiter;
use App\Security\SignatureValidator;
use App\Service\HmacAuditLogger;
use App\Service\HmacPolicyService;
use App\Service\LogService;
use App\Service\OperationalSettingsService;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Logique partagée pour les heartbeats des firmwares legacy n3pp / msp.
 *
 * Contrat (Phase 4 audit, aligné FFP3) :
 *   POST application/x-www-form-urlencoded :
 *     - api_key OR (timestamp + signature) : auth (HMAC prioritaire)
 *     - sensor    : identifiant firmware (ex. "n3pp", "msp1")
 *     - version   : version firmware (ex. "4.38")
 *     - uptime    : secondes depuis boot
 *     - free      : free heap (octets)
 *     - min       : min heap depuis boot (octets)
 *     - reboots   : compteur reboots cumulés (bootCount)
 *     - rssi      : (optionnel) RSSI WiFi en dBm
 *     - ip        : (optionnel) IP locale du device
 *
 * Table cible (whitelist stricte) :
 *   - msp1 : msp1Heartbeat / msp1HeartbeatTest
 *   - n3pp : n3ppHeartbeat / n3ppHeartbeatTest
 *
 * Schéma SQL recommandé (migration `serveur/migrations/2026_05_heartbeat_legacy.sql`) :
 *   CREATE TABLE msp1Heartbeat (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     uptime BIGINT NOT NULL,
 *     freeHeap INT NOT NULL,
 *     minHeap INT NOT NULL,
 *     reboots INT NOT NULL,
 *     rssi INT NULL,
 *     sensor VARCHAR(30) NULL,
 *     version VARCHAR(30) NULL,
 *     reading_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     INDEX idx_reading_time (reading_time)
 *   );
 */
final class LegacyHeartbeatHandler
{
    /**
     * @param string $componentName Nom pour les logs (ex. "MspHeartbeat")
     * @param string[] $allowedTables Whitelist stricte des tables possibles
     */
    public function __construct(
        private readonly LogService $logger,
        private readonly PDO $pdo,
        private readonly string $componentName,
        private readonly array $allowedTables,
        private readonly ?HmacAuditLogger $hmacAuditLogger = null,
        private readonly ?HmacPolicyService $hmacPolicyService = null,
        private readonly ?OperationalSettingsService $operationalSettings = null,
    ) {
    }

    private function opInt(string $envKey, int $default): int
    {
        return $this->operationalSettings?->int($envKey, $default)
            ?? (isset($_ENV[$envKey]) && is_numeric($_ENV[$envKey]) ? (int) $_ENV[$envKey] : $default);
    }

    public function handle(Request $request, Response $response, string $tableName): Response
    {
        if ($request->getMethod() !== 'POST') {
            return ResponseHelper::text($response, 'POST requis', 405);
        }

        // Rate-limiting optionnel par IP (defaut off) : actif si
        // FIRMWARE_RATE_LIMIT_MAX > 0. Meme politique que /post-data.
        $max = $this->opInt('FIRMWARE_RATE_LIMIT_MAX', 0);
        if ($max > 0) {
            $window = $this->opInt('FIRMWARE_RATE_LIMIT_WINDOW', 60);
            if ($window <= 0) {
                $window = 60;
            }
            $server = $request->getServerParams();
            $ip = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : 'unknown';
            $xff = $request->getHeaderLine('X-Forwarded-For');
            if ($xff !== '') {
                $first = trim(explode(',', $xff)[0]);
                if ($first !== '') {
                    $ip = $first;
                }
            }
            if ((new RateLimiter())->hit("firmware:{$this->componentName}:{$ip}", $window) > $max) {
                $this->logger->warning("{$this->componentName}: rejet rate limit code=429", ['ip' => $ip]);
                return ResponseHelper::text($response, 'Trop de requetes', 429);
            }
        }

        $params = RequestHelper::extractParams($request);
        if ($params === []) {
            return ResponseHelper::text($response, 'Donnees manquantes', 400);
        }

        $authError = $this->validateAuth($request, $params, $response);
        if ($authError !== null) {
            return $authError;
        }

        $get = static fn (string $k): string => isset($params[$k]) && is_scalar($params[$k])
            ? trim((string) $params[$k]) : '';

        $uptime = $this->sanitizeNumeric($get('uptime'));
        $free = $this->sanitizeNumeric($get('free'));
        $min = $this->sanitizeNumeric($get('min'));
        $reboots = $this->sanitizeNumeric($get('reboots'));

        if ($uptime === '' || $free === '' || $min === '' || $reboots === '') {
            $this->logger->warning("{$this->componentName}: champs manquants", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'has_uptime' => $uptime !== '',
                'has_free' => $free !== '',
                'has_min' => $min !== '',
                'has_reboots' => $reboots !== '',
            ]);
            return ResponseHelper::text($response, 'Champs manquants', 400);
        }

        $sensor = substr($get('sensor'), 0, 30) ?: null;
        $version = substr($get('version'), 0, 30) ?: null;
        $rssi = $get('rssi') !== '' ? (int) $get('rssi') : null;

        if (!in_array($tableName, $this->allowedTables, true)) {
            $this->logger->error("{$this->componentName}: table heartbeat invalide", ['table' => $tableName]);
            return ResponseHelper::text($response, 'Configuration serveur invalide', 500);
        }

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$tableName} (uptime, freeHeap, minHeap, reboots, rssi, sensor, version) "
                . 'VALUES (:uptime, :free, :min, :reboots, :rssi, :sensor, :version)'
            );
            $stmt->execute([
                ':uptime' => (int) $uptime,
                ':free' => (int) $free,
                ':min' => (int) $min,
                ':reboots' => (int) $reboots,
                ':rssi' => $rssi,
                ':sensor' => $sensor,
                ':version' => $version,
            ]);

            $this->logger->info("{$this->componentName} OK", [
                'sensor' => $sensor,
                'version' => $version,
                'uptime' => $uptime,
                'reboots' => $reboots,
            ]);

            return ResponseHelper::textClose($response, 'OK', 200);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName}: erreur insertion", [
                'msg' => $e->getMessage(),
                'table' => $tableName,
            ]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }

    /**
     * Auth : X-Sig-* prioritaire si présent, sinon HMAC body params, sinon API_KEY.
     *
     * @param array<string, mixed> $params
     */
    private function validateAuth(Request $request, array $params, Response $response): ?Response
    {
        $headerError = $this->verifyOptionalHeaderHmac($request, $response);
        if ($headerError !== null) {
            return $headerError;
        }
        if (trim($request->getHeaderLine('X-Sig-Hmac')) !== '') {
            return null;
        }

        $timestamp = $params['timestamp'] ?? null;
        $signature = $params['signature'] ?? null;

        if ($timestamp !== null && $signature !== null) {
            $sigSecret = $_ENV['API_SIG_SECRET'] ?? null;
            if (!is_string($sigSecret) || $sigSecret === '') {
                return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
            }
            $sigWindow = $this->opInt('SIG_VALID_WINDOW', 300);
            if ($sigWindow <= 0) {
                $sigWindow = 300;
            }
            if (!SignatureValidator::isValid((string) $timestamp, (string) $signature, $sigSecret, $sigWindow)) {
                $this->logger->warning("{$this->componentName}: rejet auth HMAC invalide code=401");
                $this->hmacAuditLogger?->record($this->componentName, 'reject', 'legacy_timestamp', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'version' => trim((string) ($params['version'] ?? '')),
                    'ts_received' => (string) $timestamp,
                    'window_s' => $sigWindow,
                ], 'signature_invalid');
                return ResponseHelper::text($response, 'Signature incorrecte', 401);
            }
            $this->hmacAuditLogger?->record($this->componentName, 'ok', 'legacy_timestamp', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'sensor' => trim((string) ($params['sensor'] ?? '')),
                'version' => trim((string) ($params['version'] ?? '')),
                'ts_received' => (string) $timestamp,
                'window_s' => $sigWindow,
            ]);
            return null;
        }

        if ($timestamp !== null || $signature !== null) {
            return ResponseHelper::text($response, 'Signature incomplete', 401);
        }

        // Parite avec HmacAuthTrait (post-data) : en mode strict, l'absence de
        // signature HMAC est refusee au lieu de retomber sur l'api_key. Sans cela
        // le heartbeat restait laxiste meme quand /post-data etait durci.
        $strict = $this->hmacPolicyService?->isStrictMode()
            ?? filter_var($_ENV['HMAC_STRICT_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        if ($strict) {
            $this->logger->warning("{$this->componentName}: rejet auth HMAC absent (strict) code=401", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            ]);
            $this->hmacAuditLogger?->record($this->componentName, 'reject', 'absent', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'sensor' => trim((string) ($params['sensor'] ?? '')),
                'version' => trim((string) ($params['version'] ?? '')),
            ], 'strict_mode');
            return ResponseHelper::text($response, 'Signature HMAC requise (strict mode)', 401);
        }

        // Fallback API_KEY
        $apiKey = isset($params['api_key']) ? trim((string) $params['api_key']) : '';
        $expected = $_ENV['API_KEY'] ?? '';
        if ($expected === '') {
            return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
        }
        if (!hash_equals($expected, $apiKey)) {
            $this->logger->warning("{$this->componentName}: rejet auth api_key code=401", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            ]);
            return ResponseHelper::text($response, 'Cle API invalide', 401);
        }

        return null;
    }

    /**
     * Vérifie les en-têtes X-Sig-* si présents (parité FFP3 / post-data body-signing).
     */
    private function verifyOptionalHeaderHmac(Request $request, Response $response): ?Response
    {
        $timestamp = trim($request->getHeaderLine('X-Sig-Timestamp'));
        $nonce = trim($request->getHeaderLine('X-Sig-Nonce'));
        $signature = trim($request->getHeaderLine('X-Sig-Hmac'));

        if ($timestamp === '' && $nonce === '' && $signature === '') {
            return null;
        }

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            $this->logger->warning("{$this->componentName}: rejet auth X-Sig incomplete code=401");
            return ResponseHelper::text($response, 'Signature incomplete', 401);
        }

        $sigSecret = $_ENV['API_SIG_SECRET'] ?? null;
        if (!is_string($sigSecret) || $sigSecret === '') {
            return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
        }

        $sigWindow = $this->opInt('SIG_VALID_WINDOW', 300);
        if ($sigWindow <= 0) {
            $sigWindow = 300;
        }

        $body = $request->getAttribute(RawPostBodyMiddleware::ATTRIBUTE);
        if (!is_string($body) || $body === '') {
            $body = (string) $request->getBody();
        }

        if (!SignatureValidator::isValidForBody($timestamp, $nonce, $body, $signature, $sigSecret, $sigWindow)) {
            $this->logger->warning("{$this->componentName}: rejet auth X-Sig invalide code=401");
            $this->hmacAuditLogger?->record($this->componentName, 'reject', 'x_sig_body', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'ts_received' => $timestamp,
                'nonce_len' => strlen($nonce),
                'window_s' => $sigWindow,
                'body_len' => strlen($body),
            ], 'signature_invalid');
            return ResponseHelper::text($response, 'Signature incorrecte', 401);
        }

        $this->hmacAuditLogger?->record($this->componentName, 'ok', 'x_sig_body', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            'ts_received' => $timestamp,
            'nonce_len' => strlen($nonce),
            'window_s' => $sigWindow,
            'body_len' => strlen($body),
        ]);

        return null;
    }

    private function sanitizeNumeric(string $data): string
    {
        $filtered = filter_var(trim($data), FILTER_SANITIZE_NUMBER_INT);
        return $filtered !== false ? $filtered : '';
    }
}
