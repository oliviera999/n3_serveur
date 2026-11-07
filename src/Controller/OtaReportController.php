<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Controller\Concerns\OperationalSettingsTrait;
use App\Repository\DeviceContactRepository;
use App\Repository\DeviceEventRepository;
use App\Security\SignatureValidator;
use App\Service\ContactRecorder;
use App\Service\HmacAuditLogger;
use App\Service\LogService;
use App\Service\OperationalSettingsService;
use App\Util\ClientIpResolver;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use App\Util\SignedBodyResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * `POST /ffp3/ota-report` (+ `/n3pp/ota-report`, `/msp1/ota-report`, variantes de test) :
 * réception des ÉVÉNEMENTS du firmware — rapport OTA (`check`, `start`, `progress`, `done`, `fail`,
 * `rollback`, `marked_valid`), journal de bord (WiFi, DNS/TLS, HTTP, NTP, veille, panic) et
 * informations de boot — persistés en NVS par le firmware et renvoyés jusqu'à acquittement.
 *
 * Contrat (form-urlencoded, HMAC OBLIGATOIRE — aucun repli clé API : un rapport non signé est
 * refusé 401 ; la traçabilité n'affaiblit pas l'authentification) :
 *   X-Sig-Timestamp / X-Sig-Nonce / X-Sig-Hmac  (HMAC-SHA256 ts\nnonce\nbody — contrat ffp5cs ≥ 13.80)
 *   ou timestamp + signature dans le corps     (HMAC-SHA256(timestamp) — contrat n3pp/msp)
 *   board=<id carte>  version=<firmware>  uptime=<s>
 *   events=<JSON compact> : [{"q":<seq>,"t":<epoch ou 0>,"u":<uptime s>,"e":"<événement>","d":"<détail>"}, ...]
 *     (≤ 32 événements, détail ≤ 255 caractères, séquence croissante par carte)
 *   src=ota_report|heartbeat_log|boot (défaut ota_report)
 *
 * Réponse JSON : {"status":"ok","acked":[<seq>...],"server_ts":<epoch>} + en-têtes Date / X-Server-Ts.
 * Le firmware purge de son anneau les séquences acquittées ; un doublon (déjà reçu) est acquitté.
 */
final class OtaReportController
{
    use OperationalSettingsTrait;

    public const MAX_EVENTS = 32;
    public const MAX_BODY_BYTES = 16384;

    public function __construct(
        private LogService $logger,
        private DeviceEventRepository $events,
        private ?ContactRecorder $contacts = null,
        private ?HmacAuditLogger $hmacAuditLogger = null,
        ?OperationalSettingsService $operationalSettings = null,
    ) {
        $this->operationalSettings = $operationalSettings;
    }

    /**
     * @param array<string, string> $args `family` fourni par la route (ffp3, n3pp, msp1)
     */
    public function handle(Request $request, Response $response, array $args = []): Response
    {
        $family = strtolower((string) ($args['family'] ?? 'ffp3'));
        if (!in_array($family, ['ffp3', 'n3pp', 'msp1'], true)) {
            return $this->reply($response, ['status' => 'error', 'message' => 'famille inconnue'], 404);
        }
        $ip = ClientIpResolver::resolve($request);

        if ($request->getMethod() !== 'POST') {
            return $this->reply($response, ['status' => 'error', 'message' => 'POST requis'], 405);
        }

        $rawBody = SignedBodyResolver::resolve($request)['body'];
        if ($rawBody === '') {
            $rawBody = (string) $request->getBody();
        }
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->reply($response, ['status' => 'error', 'message' => 'corps trop long'], 413);
        }
        $params = RequestHelper::extractParams($request, $rawBody);
        if ($params === [] && $rawBody !== '' && !str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json')) {
            // Corps x-www-form-urlencoded non analysé en amont (pas de BodyParsingMiddleware) : le corps
            // brut, celui qui est signé, fait foi.
            parse_str($rawBody, $params);
        }
        $get = static fn (string $k): string => isset($params[$k]) && is_scalar($params[$k]) ? trim((string) $params[$k]) : '';
        $version = substr($get('version'), 0, 30);
        $board = substr($get('board'), 0, 64);

        [$authMode, $tsReceived, $reason] = $this->authenticate($request, $params, $rawBody);
        if ($authMode === null) {
            $status = $reason === 'config' ? 500 : 401;
            $this->logger->warning('OtaReport: rejet auth code=' . $status, ['family' => $family, 'ip' => $ip, 'reason' => $reason]);
            $this->hmacAuditLogger?->record('OtaReport', 'reject', 'x_sig_body', ['ip' => $ip, 'ts_received' => $tsReceived], $reason);
            $this->contacts?->record([
                'family' => $family, 'kind' => DeviceContactRepository::KIND_OTA_REPORT, 'accepted' => false,
                'http_status' => $status, 'auth_mode' => 'none', 'reject_reason' => $reason, 'ts_received' => $tsReceived,
                'firmware_version' => $version, 'ip' => $ip,
            ]);

            return $this->reply($response, ['status' => 'error', 'message' => $reason === 'config' ? 'Configuration serveur manquante' : 'Signature requise ou incorrecte'], $status);
        }

        $source = $get('src');
        if (!in_array($source, [DeviceEventRepository::SOURCE_OTA_REPORT, DeviceEventRepository::SOURCE_HEARTBEAT_LOG, DeviceEventRepository::SOURCE_BOOT], true)) {
            $source = DeviceEventRepository::SOURCE_OTA_REPORT;
        }
        $uptime = $get('uptime') !== '' && ctype_digit($get('uptime')) ? (int) $get('uptime') : null;
        $decoded = json_decode($get('events'), true);
        $list = is_array($decoded) ? array_slice(array_values($decoded), 0, self::MAX_EVENTS) : [];

        $acked = [];
        $stored = 0;
        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['e']) || !is_scalar($item['e'])) {
                continue;
            }
            $event = preg_replace('/[^a-z0-9_.-]/i', '', substr((string) $item['e'], 0, 32)) ?? '';
            if ($event === '') {
                continue;
            }
            $seq = isset($item['q']) && is_numeric($item['q']) ? (int) $item['q'] : null;
            try {
                $this->events->record([
                    'family' => $family,
                    'env' => TableConfig::getEnvironment(),
                    'board' => $board,
                    'firmware_version' => $version,
                    'source' => $source,
                    'seq' => $seq,
                    'device_ts' => isset($item['t']) && is_numeric($item['t']) ? (int) $item['t'] : null,
                    'uptime_s' => isset($item['u']) && is_numeric($item['u']) ? (int) $item['u'] : $uptime,
                    'event' => $event,
                    'detail' => isset($item['d']) && is_scalar($item['d']) ? substr((string) $item['d'], 0, 255) : null,
                ]);
                $stored++;
            } catch (\Throwable $e) {
                $this->logger->error('OtaReport: enregistrement impossible', ['error' => $e->getMessage(), 'event' => $event]);
                continue;
            }
            if ($seq !== null) {
                $acked[] = $seq;
            }
        }

        $this->logger->info('OtaReport reçu', [
            'family' => $family, 'env' => TableConfig::getEnvironment(), 'board' => $board, 'version' => $version,
            'source' => $source, 'events' => count($list), 'stored' => $stored,
        ]);
        $this->hmacAuditLogger?->record('OtaReport', 'ok', $authMode, ['ip' => $ip, 'ts_received' => $tsReceived]);
        $this->contacts?->record([
            'family' => $family, 'kind' => DeviceContactRepository::KIND_OTA_REPORT, 'accepted' => true, 'http_status' => 200,
            'auth_mode' => $authMode, 'ts_received' => $tsReceived, 'firmware_version' => $version, 'ip' => $ip,
        ]);

        return $this->reply($response, ['status' => 'ok', 'acked' => $acked, 'stored' => $stored], 200);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: ?string, 1: ?string, 2: ?string} [mode d'auth ou null, ts reçu, motif de rejet]
     */
    private function authenticate(Request $request, array $params, string $rawBody): array
    {
        $secret = $_ENV['API_SIG_SECRET'] ?? null;
        if (!is_string($secret) || $secret === '') {
            return [null, null, 'config'];
        }
        $window = $this->opInt('SIG_VALID_WINDOW', 300);
        if ($window <= 0) {
            $window = 300;
        }

        $ts = trim($request->getHeaderLine('X-Sig-Timestamp'));
        $nonce = trim($request->getHeaderLine('X-Sig-Nonce'));
        $sig = trim($request->getHeaderLine('X-Sig-Hmac'));
        if ($ts !== '' || $nonce !== '' || $sig !== '') {
            if ($ts === '' || $nonce === '' || $sig === '') {
                return [null, $ts !== '' ? $ts : null, 'signature_incomplete'];
            }

            return SignatureValidator::isValidForBody($ts, $nonce, $rawBody, $sig, $secret, $window)
                ? ['x_sig_body', $ts, null]
                : [null, $ts, 'signature_invalid'];
        }

        $bodyTs = isset($params['timestamp']) && is_scalar($params['timestamp']) ? trim((string) $params['timestamp']) : '';
        $bodySig = isset($params['signature']) && is_scalar($params['signature']) ? trim((string) $params['signature']) : '';
        if ($bodyTs !== '' && $bodySig !== '') {
            return SignatureValidator::isValid($bodyTs, $bodySig, $secret, $window)
                ? ['legacy_timestamp', $bodyTs, null]
                : [null, $bodyTs, 'signature_invalid'];
        }

        return [null, $bodyTs !== '' ? $bodyTs : null, 'signature_missing'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function reply(Response $response, array $data, int $status): Response
    {
        $now = time();
        $data['server_ts'] = $now;
        $response = ResponseHelper::json($response, $data, $status);
        foreach (ContactRecorder::serverTimeHeaders($now) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withHeader('Connection', 'close');
    }
}
