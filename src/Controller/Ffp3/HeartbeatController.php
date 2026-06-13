<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Config\Paths;
use App\Config\TableConfig;
use App\Middleware\RawPostBodyMiddleware;
use App\Repository\HeartbeatRepository;
use App\Security\SignatureValidator;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur pour le heartbeat ESP32 FFP3 (aquaponie)
 * Réception des battements de coeur (uptime, mémoire, reboot count).
 *
 * L'écriture est déléguée à HeartbeatRepository (whitelist de tables par environnement).
 */
class HeartbeatController
{
    public function __construct(
        private LogService $logger,
        private ErrorAlertService $errorAlert,
        private HeartbeatRepository $heartbeatRepo,
    ) {
    }

    /**
     * POST /heartbeat (PROD) ou /heartbeat-test (TEST)
     * Réception battement de coeur de l'ESP32
     *
     * Champs attendus: uptime, free, min, reboots, crc
     * CRC32 calculé sur "uptime={uptime}&free={free}&min={min}&reboots={reboots}"
     */
    public function handle(Request $request, Response $response): Response
    {
        // Vérifier méthode POST
        if ($request->getMethod() !== 'POST') {
            $this->logger->warning('Heartbeat: Méthode non autorisée', ['method' => $request->getMethod()]);
            return ResponseHelper::text($response, 'Méthode non autorisée', 405);
        }

        $params = RequestHelper::extractParams($request);

        // Valeur scalar ou chaîne vide (évite erreur si POST malformé avec tableaux)
        $get = static fn (string $k): string => isset($params[$k]) && is_scalar($params[$k])
            ? (string) $params[$k] : '';

        // Récupération des paramètres (valeurs numériques)
        $uptime = $this->sanitizeNumeric($get('uptime'));
        $free = $this->sanitizeNumeric($get('free'));
        $min = $this->sanitizeNumeric($get('min'));
        $reboots = $this->sanitizeNumeric($get('reboots'));
        $crc = strtoupper($this->sanitizeString($get('crc')));

        // Vérification des champs requis
        if ($uptime === '' || $free === '' || $min === '' || $reboots === '' || $crc === '') {
            $this->logger->warning('Heartbeat: Champs manquants', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
            return ResponseHelper::text($response, 'Champs manquants', 400);
        }

        // Authentification HMAC OPTIONNELLE (rétrocompatible) : le firmware FFP5CS envoie déjà
        // les en-têtes X-Sig-* sur le heartbeat (via WebClient::httpRequest) quand HMAC est activé.
        // Si présents → on vérifie la signature (401 si invalide). Si absents → comportement
        // historique inchangé (CRC32 seul). Le CRC32 reste un contrôle d'intégrité, pas d'auth.
        $hmacReject = $this->verifyOptionalHeaderHmac($request, $response);
        if ($hmacReject !== null) {
            return $hmacReject;
        }

        // Vérification CRC32
        $raw = "uptime={$uptime}&free={$free}&min={$min}&reboots={$reboots}";
        $calcCrc = strtoupper(hash('crc32b', $raw));

        if ($crc !== $calcCrc) {
            $this->logger->warning('Heartbeat: CRC mismatch', [
                'calculated' => $calcCrc,
                'received' => $crc,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
            ]);
            return ResponseHelper::text($response, "CRC mismatch (calc={$calcCrc}, posted={$crc})", 400);
        }

        // IP locale optionnelle : écriture dans un fichier (pas en BDD)
        $deviceIp = $this->sanitizeIp($get('ip'));
        if ($deviceIp !== '') {
            $projectRoot = Paths::getProjectRoot();
            $varDir = $projectRoot . '/var';
            if (!is_dir($varDir)) {
                @mkdir($varDir, 0755, true);
            }
            $ipFile = $varDir . '/last_device_ip_' . TableConfig::getEnvironment() . '.txt';
            @file_put_contents($ipFile, $deviceIp);
        }

        // Insertion en base de données via repository (whitelist tables par env)
        try {
            $this->heartbeatRepo->insert((int) $uptime, (int) $free, (int) $min, (int) $reboots);

            $this->logger->info('Heartbeat reçu', [
                'env' => TableConfig::getEnvironment(),
                'uptime' => $uptime,
                'reboots' => $reboots,
            ]);

            return ResponseHelper::textClose($response, 'OK', 200);
        } catch (\Throwable $e) {
            $errorMessage = 'Heartbeat: Erreur insertion';
            $this->logger->error($errorMessage, ['error' => $e->getMessage()]);

            $this->errorAlert->recordError($errorMessage, [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }

    /**
     * Vérifie la signature HMAC d'en-têtes X-Sig-* SI elle est présente (rétrocompatible).
     *
     * Contrat aligné sur PostDataController::validateHeaderHmac et le firmware FFP5CS
     * (hmac_sign.cpp) : message = timestamp + "\n" + nonce + "\n" + body, où body est le
     * corps POST brut effectivement signé (capté par RawPostBodyMiddleware).
     *
     * @return Response|null  null si OK ou si aucun en-tête X-Sig (fallback CRC) ;
     *                        Response 401/500 si en-têtes présents mais invalides.
     */
    private function verifyOptionalHeaderHmac(Request $request, Response $response): ?Response
    {
        $timestamp = trim($request->getHeaderLine('X-Sig-Timestamp'));
        $nonce = trim($request->getHeaderLine('X-Sig-Nonce'));
        $signature = trim($request->getHeaderLine('X-Sig-Hmac'));

        // Aucun en-tête de signature → device legacy : on laisse passer (CRC seul).
        if ($timestamp === '' && $nonce === '' && $signature === '') {
            return null;
        }

        // En-têtes partiels → rejet (signature incomplète).
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            $this->logger->warning('Heartbeat: rejet auth X-Sig incomplete code=401', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'has_timestamp' => $timestamp !== '',
                'has_nonce' => $nonce !== '',
                'has_signature' => $signature !== '',
            ]);
            return ResponseHelper::text($response, 'Signature incomplete', 401);
        }

        $sigSecret = $_ENV['API_SIG_SECRET'] ?? null;
        if (!is_string($sigSecret) || $sigSecret === '') {
            $this->logger->error('Heartbeat: rejet config API_SIG_SECRET manquante code=500');
            return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
        }

        $sigWindow = (int) ($_ENV['SIG_VALID_WINDOW'] ?? 300);
        if ($sigWindow <= 0) {
            $sigWindow = 300;
        }

        // Corps signé = corps POST brut (capté avant consommation par mod_php).
        $body = $request->getAttribute(RawPostBodyMiddleware::ATTRIBUTE);
        if (!is_string($body) || $body === '') {
            $body = (string) $request->getBody();
        }

        $valid = SignatureValidator::isValidForBody(
            $timestamp,
            $nonce,
            $body,
            $signature,
            $sigSecret,
            $sigWindow
        );

        if (!$valid) {
            $this->logger->warning('Heartbeat: rejet auth X-Sig invalide code=401', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'ts_received' => $timestamp,
                'nonce_len' => strlen($nonce),
                'window_s' => $sigWindow,
                'body_len' => strlen($body),
            ]);
            return ResponseHelper::text($response, 'Signature incorrecte', 401);
        }

        $this->logger->info('Heartbeat: auth HMAC OK', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);

        return null;
    }

    /**
     * Nettoie et sécurise une valeur POST numérique
     *
     * @param string $data Valeur à nettoyer
     * @return string Valeur nettoyée (chiffres uniquement)
     */
    private function sanitizeNumeric(string $data): string
    {
        $filtered = filter_var(trim($data), FILTER_SANITIZE_NUMBER_INT);
        return $filtered !== false ? $filtered : '';
    }

    /**
     * Nettoie et sécurise une valeur POST string (pour CRC)
     *
     * @param string $data Valeur à nettoyer
     * @return string Valeur nettoyée
     */
    private function sanitizeString(string $data): string
    {
        return htmlspecialchars(trim(stripslashes($data)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Nettoie une IP (chiffres et points uniquement, max 45 caractères)
     *
     * @param string $data Valeur reçue (ex. 192.168.1.42)
     * @return string IP sanitized ou chaîne vide
     */
    private function sanitizeIp(string $data): string
    {
        $trimmed = trim($data);
        if ($trimmed === '' || strlen($trimmed) > 45) {
            return '';
        }
        $filtered = preg_replace('/[^0-9.]/', '', $trimmed);
        return strlen($filtered) <= 45 ? $filtered : substr($filtered, 0, 45);
    }
}
