<?php

declare(strict_types=1);

namespace App\Controller\Concerns;

use App\Security\SignatureValidator;
use App\Service\LogService;
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
    ) {
    }

    public function handle(Request $request, Response $response, string $tableName): Response
    {
        if ($request->getMethod() !== 'POST') {
            return ResponseHelper::text($response, 'POST requis', 405);
        }

        $params = RequestHelper::extractParams($request);
        if ($params === []) {
            return ResponseHelper::text($response, 'Donnees manquantes', 400);
        }

        $authError = $this->validateAuth($params, $response);
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
     * Auth : HMAC FFP3 prioritaire si timestamp+signature, sinon API_KEY.
     *
     * @param array<string, mixed> $params
     */
    private function validateAuth(array $params, Response $response): ?Response
    {
        $timestamp = $params['timestamp'] ?? null;
        $signature = $params['signature'] ?? null;

        if ($timestamp !== null && $signature !== null) {
            $sigSecret = $_ENV['API_SIG_SECRET'] ?? null;
            if (!is_string($sigSecret) || $sigSecret === '') {
                return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
            }
            $sigWindow = (int) ($_ENV['SIG_VALID_WINDOW'] ?? 300);
            if ($sigWindow <= 0) {
                $sigWindow = 300;
            }
            if (!SignatureValidator::isValid((string) $timestamp, (string) $signature, $sigSecret, $sigWindow)) {
                $this->logger->warning("{$this->componentName}: rejet auth HMAC invalide code=401");
                return ResponseHelper::text($response, 'Signature incorrecte', 401);
            }
            return null;
        }

        if ($timestamp !== null || $signature !== null) {
            return ResponseHelper::text($response, 'Signature incomplete', 401);
        }

        // Parite avec HmacAuthTrait (post-data) : en mode strict, l'absence de
        // signature HMAC est refusee au lieu de retomber sur l'api_key. Sans cela
        // le heartbeat restait laxiste meme quand /post-data etait durci.
        $strict = filter_var($_ENV['HMAC_STRICT_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        if ($strict) {
            $this->logger->warning("{$this->componentName}: rejet auth HMAC absent (strict) code=401", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            ]);
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

    private function sanitizeNumeric(string $data): string
    {
        $filtered = filter_var(trim($data), FILTER_SANITIZE_NUMBER_INT);
        return $filtered !== false ? $filtered : '';
    }
}
