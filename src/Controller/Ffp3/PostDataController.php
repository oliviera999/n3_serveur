<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Config\TableConfig;
use App\Controller\AbstractPostDataController;
use App\Domain\SensorData;
use App\Middleware\RawPostBodyMiddleware;
use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorRepository;
use App\Security\Ffp3HmacPostBody;
use App\Security\SignatureValidator;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Reception des donnees POST du firmware ffp5cs (aquaponie).
 * Herite du flux commun, avec HMAC, sync outputs et cache.
 */
class PostDataController extends AbstractPostDataController
{
    /** POST minimal ack_command (firmware) — pas d'INSERT ligne capteur. */
    private bool $ackOnlyPost = false;

    /** @var array{timestamp: string, nonce: string, signature: string, body: string, body_source?: string}|null */
    private ?array $hmacHeaderAuth = null;

    public function __construct(
        LogService $logger,
        private ErrorAlertService $errorAlert,
        private SensorRepository $sensorRepo,
        private OutputRepository $outputRepo,
        private BoardRepository $boardRepo
    ) {
        parent::__construct($logger);
    }

    protected function componentName(): string
    {
        return 'PostData';
    }

    /**
     * Validation HMAC spécifique à FFP3 (avant la clé API legacy).
     *
     * - Accepte d'abord les en-têtes FFP5CS v13.80+ `X-Sig-*`, signés sur
     *   `timestamp + "\n" + nonce + "\n" + body` (format firmware actuel).
     * - Si `HMAC_STRICT_MODE=true` : exige une signature HMAC, refuse le fallback api_key.
     * - Si `HMAC_NONCE_REQUIRED=true` : exige aussi `post_id` et utilise
     *   `isValidWithNonce()` (message HMAC = `<timestamp>|<post_id>`).
     *   Le firmware FFP5CS doit alors construire la signature de la même manière.
     * - Sinon (compat) : variante historique HMAC(timestamp, secret).
     */
    protected function validateAuth(array $params, Response $response): ?Response
    {
        if ($this->hmacHeaderAuth !== null) {
            return $this->validateHeaderHmac($params, $response);
        }

        $timestamp = $params['timestamp'] ?? null;
        $signature = $params['signature'] ?? null;

        $strict = filter_var($_ENV['HMAC_STRICT_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $nonceRequired = filter_var($_ENV['HMAC_NONCE_REQUIRED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        if ($timestamp !== null || $signature !== null) {
            if ($timestamp === null || $signature === null) {
                $this->logger->warning('PostData: rejet auth signature incomplete code=401', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'version' => trim((string) ($params['version'] ?? '')),
                    'post_id' => isset($params['post_id']) ? substr(trim((string) $params['post_id']), 0, 64) : null,
                ]);
                return ResponseHelper::text($response, 'Signature incomplete', 401);
            }

            $sigSecret = $_ENV['API_SIG_SECRET'] ?? null;
            if ($sigSecret === null || $sigSecret === '') {
                $errorMessage = 'Variable API_SIG_SECRET manquante dans .env';
                $this->logger->error('PostData: rejet config API_SIG_SECRET manquante code=500');
                $this->errorAlert->recordError($errorMessage);
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
                    $this->logger->warning('PostData: rejet auth HMAC sans post_id (nonce requis) code=401', [
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                        'sensor' => trim((string) ($params['sensor'] ?? '')),
                        'version' => trim((string) ($params['version'] ?? '')),
                    ]);
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
                $this->logger->warning('PostData: rejet auth HMAC invalide code=401', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'version' => trim((string) ($params['version'] ?? '')),
                    'post_id' => $postId !== '' ? $postId : null,
                    'nonce_required' => $nonceRequired,
                ]);
                return ResponseHelper::text($response, 'Signature incorrecte', 401);
            }
            $this->authenticatedByHmac = true;

            return null;
        }

        // Aucun HMAC fourni : selon HMAC_STRICT_MODE, on refuse ou on tombe sur api_key (compat).
        if ($strict) {
            $this->logger->warning('PostData: rejet auth HMAC absent (strict) code=401', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'sensor' => trim((string) ($params['sensor'] ?? '')),
                'version' => trim((string) ($params['version'] ?? '')),
            ]);
            return ResponseHelper::text($response, 'Signature HMAC requise (strict mode)', 401);
        }

        $this->logger->info('Aucune signature fournie – authentification api_key requise');

        return null;
    }

    /**
     * Capture le corps brut et les en-têtes HMAC avant validation.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function prepareParamsForAuth(Request $request, array $params): array
    {
        $timestamp = trim($request->getHeaderLine('X-Sig-Timestamp'));
        $nonce = trim($request->getHeaderLine('X-Sig-Nonce'));
        $signature = trim($request->getHeaderLine('X-Sig-Hmac'));
        if ($timestamp === '' && $nonce === '' && $signature === '') {
            $this->hmacHeaderAuth = null;
            return $params;
        }

        $rawBody = $request->getAttribute(RawPostBodyMiddleware::ATTRIBUTE);
        if (!is_string($rawBody)) {
            $rawBody = (string) $request->getBody();
        }
        $bodySource = 'raw';
        if ($rawBody === '') {
            $rawBody = Ffp3HmacPostBody::buildFromParams($params);
            $bodySource = 'canonical';
            if ($rawBody !== '') {
                $this->logger->info('PostData: corps HMAC reconstitue (php://input vide)', [
                    'body_source' => $bodySource,
                    'body_len' => strlen($rawBody),
                ]);
            }
        }

        $this->hmacHeaderAuth = [
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => $signature,
            'body' => $rawBody,
            'body_source' => $bodySource,
        ];

        return $params;
    }

    private function validateHeaderHmac(array $params, Response $response): ?Response
    {
        $headerAuth = $this->hmacHeaderAuth;
        if ($headerAuth === null) {
            return null;
        }

        if ($headerAuth['timestamp'] === '' || $headerAuth['nonce'] === '' || $headerAuth['signature'] === '') {
            $this->logger->warning('PostData: rejet auth X-Sig incomplete code=401', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'sensor' => trim((string) ($params['sensor'] ?? '')),
                'version' => trim((string) ($params['version'] ?? '')),
                'has_timestamp' => $headerAuth['timestamp'] !== '',
                'has_nonce' => $headerAuth['nonce'] !== '',
                'has_signature' => $headerAuth['signature'] !== '',
            ]);
            return ResponseHelper::text($response, 'Signature incomplete', 401);
        }

        $sigSecret = $_ENV['API_SIG_SECRET'] ?? null;
        if ($sigSecret === null || $sigSecret === '') {
            $errorMessage = 'Variable API_SIG_SECRET manquante dans .env';
            $this->logger->error('PostData: rejet config API_SIG_SECRET manquante code=500');
            $this->errorAlert->recordError($errorMessage);
            return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
        }

        $sigWindow = (int) ($_ENV['SIG_VALID_WINDOW'] ?? 300);
        if ($sigWindow <= 0) {
            $sigWindow = 300;
        }

        $valid = SignatureValidator::isValidForBody(
            $headerAuth['timestamp'],
            $headerAuth['nonce'],
            $headerAuth['body'],
            $headerAuth['signature'],
            $sigSecret,
            $sigWindow
        );

        if (!$valid) {
            $this->logger->warning('PostData: rejet auth X-Sig invalide code=401', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                'sensor' => trim((string) ($params['sensor'] ?? '')),
                'version' => trim((string) ($params['version'] ?? '')),
                'ts_received' => $headerAuth['timestamp'],
                'nonce_len' => strlen($headerAuth['nonce']),
                'window_s' => $sigWindow,
                'body_source' => $headerAuth['body_source'] ?? 'unknown',
                'body_len' => strlen($headerAuth['body']),
            ]);
            return ResponseHelper::text($response, 'Signature incorrecte', 401);
        }

        $this->authenticatedByHmac = true;

        return null;
    }

    /**
     * Override handle pour conserver le diagnostic latence (dédup post_id après auth : afterValidatedParams).
     */
    public function handle(Request $request, Response $response): Response
    {
        $this->ackOnlyPost = false;
        $this->hmacHeaderAuth = null;
        set_time_limit(30);  // Marge vs timeout client 18 s — évite kill PHP si traitement BDD lent
        $tReceived = microtime(true);
        $sec = (int) $tReceived;
        $us = (int) (($tReceived - $sec) * 1000000);
        $this->logger->info(
            'PostData request received at={at} ts={ts}',
            ['at' => date('Y-m-d H:i:s', $sec) . '.' . sprintf('%06d', $us), 'ts' => round($tReceived, 3)]
        );

        return parent::handle($request, $response);
    }

    protected function afterValidatedParams(Request $request, Response $response, array $params): ?Response
    {
        $postId = isset($params['post_id']) && is_scalar($params['post_id'])
            ? substr(trim((string) $params['post_id']), 0, 64) : null;
        if ($postId !== null && $postId !== '' && $this->sensorRepo->existsByPostId($postId)) {
            $this->logger->info('PostData duplicate skipped post_id={pid}', ['pid' => $postId]);

            return ResponseHelper::textClose($response, 'Donnees deja enregistrees', 200);
        }

        return null;
    }

    protected function buildSensorData(array $params, \Closure $sanitize, \Closure $toFloat, \Closure $toInt): object
    {
        $mailMaxLen = 255;
        $mail = $sanitize('mail');
        $mail = $mail !== null ? substr($mail, 0, $mailMaxLen) : null;
        $mailNotif = $sanitize('mailNotif');
        $mailNotif = $mailNotif !== null ? substr($mailNotif, 0, $mailMaxLen) : null;

        $postId = $sanitize('post_id');
        $postId = $postId !== null ? substr($postId, 0, 64) : null;
        $tideEvent = $sanitize('tideEvent');
        if ($tideEvent !== null) {
            $tideEvent = strtolower(substr(trim($tideEvent), 0, 16));
            if (!in_array($tideEvent, ['none', 'peak', 'trough'], true)) {
                $tideEvent = null;
            }
        }

        $this->ackOnlyPost = isset($params['ack_command']) && is_scalar($params['ack_command'])
            && trim((string) $params['ack_command']) !== '';

        return new SensorData(
            sensor: substr($sanitize('sensor') ?? '', 0, 30),
            version: substr($sanitize('version') ?? '', 0, 30),
            tempAir: $toFloat('TempAir'),
            humidite: $toFloat('Humidite'),
            tempEau: $toFloat('TempEau'),
            eauPotager: $toFloat('EauPotager'),
            eauAquarium: $toFloat('EauAquarium'),
            eauReserve: $toFloat('EauReserve'),
            diffMaree: $toFloat('diffMaree'),
            luminosite: $toFloat('Luminosite'),
            etatPompeAqua: $toInt('etatPompeAqua'),
            etatPompeTank: $toInt('etatPompeTank'),
            etatHeat: $toInt('etatHeat'),
            etatUV: $toInt('etatUV'),
            bouffeMatin: $toInt('bouffeMatin'),
            bouffeMidi: $toInt('bouffeMidi'),
            bouffePetits: $toInt('bouffePetits'),
            bouffeGros: $toInt('bouffeGros'),
            aqThreshold: $toInt('aqThreshold'),
            tankThreshold: $toInt('tankThreshold'),
            chauffageThreshold: $toFloat('chauffageThreshold'),
            mail: $mail,
            mailNotif: $mailNotif,
            resetMode: $toInt('resetMode'),
            bouffeSoir: $toInt('bouffeSoir'),
            tempsGros: $toInt('tempsGros'),
            tempsPetits: $toInt('tempsPetits'),
            tempsRemplissageSec: $toInt('tempsRemplissageSec'),
            limFlood: $toInt('limFlood'),
            wakeUp: $toInt('WakeUp'),
            freqWakeUp: $toInt('FreqWakeUp'),
            configSynced: $toInt('configSynced'),
            pression: $toFloat('Pression'),
            postId: $postId,
            tideEvent: $tideEvent,
            tideTrend: $toInt('tideTrend'),
            tideNoiseMm: $toInt('tideNoiseMm'),
            tideWindowMs: $toInt('tideWindowMs'),
            tideExtremeMm: $toInt('tideExtremeMm')
        );
    }

    protected function insertData(object $data): void
    {
        if ($this->ackOnlyPost) {
            $this->boardRepo->updateLastRequest(TableConfig::getPostDataBoardId());
            $this->logger->info('PostData: ACK-only enregistre (pas INSERT capteur)');

            return;
        }

        $t0 = microtime(true);
        $t1 = $t2 = $t3 = null;

        $this->sensorRepo->insertAtomically($data, function (SensorData $insertedData) use (&$t1, &$t2, &$t3): void {
            $t1 = microtime(true);

            $this->outputRepo->ensureAquariumPumpForceRowExists();
            $this->outputRepo->syncStatesFromSensorData($insertedData);
            $t2 = microtime(true);

            $this->boardRepo->updateLastRequest(TableConfig::getPostDataBoardId());
            $t3 = microtime(true);
        });

        $t1 ??= $t0;
        $t2 ??= $t1;
        $t3 ??= $t2;

        $this->logger->info(
            'PostData timing_ms: insert={insertMs} sync={syncMs} board={boardMs} total={totalMs}',
            [
                'insertMs' => round(($t1 - $t0) * 1000),
                'syncMs' => round(($t2 - $t1) * 1000),
                'boardMs' => round(($t3 - $t2) * 1000),
                'totalMs' => round(($t3 - $t0) * 1000),
            ]
        );
    }
}
