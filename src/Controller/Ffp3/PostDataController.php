<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Controller\AbstractPostDataController;
use App\Config\TableConfig;
use App\Domain\SensorData;
use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorRepository;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use App\Security\SignatureValidator;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Reception des donnees POST du firmware ffp5cs (aquaponie).
 * Herite du flux commun, avec HMAC, sync outputs et cache.
 */
class PostDataController extends AbstractPostDataController
{
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
     * HMAC validation spécifique à FFP3 (avant la clé API legacy).
     */
    protected function validateAuth(array $params, Response $response): ?Response
    {
        $timestamp = $params['timestamp'] ?? null;
        $signature = $params['signature'] ?? null;

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
            if ($sigSecret === null) {
                $errorMessage = 'Variable API_SIG_SECRET manquante dans .env';
                $this->logger->error('PostData: rejet config API_SIG_SECRET manquante code=500');
                $this->errorAlert->recordError($errorMessage);
                return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
            }

            $sigWindow = (int) ($_ENV['SIG_VALID_WINDOW'] ?? 300);

            if (!SignatureValidator::isValid((string) $timestamp, (string) $signature, $sigSecret, $sigWindow)) {
                $this->logger->warning('PostData: rejet auth HMAC invalide code=401', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                    'sensor' => trim((string) ($params['sensor'] ?? '')),
                    'version' => trim((string) ($params['version'] ?? '')),
                    'post_id' => isset($params['post_id']) ? substr(trim((string) $params['post_id']), 0, 64) : null,
                ]);
                return ResponseHelper::text($response, 'Signature incorrecte', 401);
            }
        } else {
            $this->logger->info('Aucune signature fournie – fallback API_KEY');
        }

        return null;
    }

    /**
     * Override handle pour conserver le diagnostic latence et la déduplication.
     */
    public function handle(Request $request, Response $response): Response
    {
        set_time_limit(30);  // Marge vs timeout client 18 s — évite kill PHP si traitement BDD lent
        $tReceived = microtime(true);
        $sec = (int) $tReceived;
        $us = (int) (($tReceived - $sec) * 1000000);
        $this->logger->info(
            'PostData request received at={at} ts={ts}',
            ['at' => date('Y-m-d H:i:s', $sec) . '.' . sprintf('%06d', $us), 'ts' => round($tReceived, 3)]
        );

        // Déduplication par post_id avant le flux commun
        $params = $request->getParsedBody();
        if (is_array($params)) {
            $postId = isset($params['post_id']) && is_scalar($params['post_id'])
                ? substr(trim((string) $params['post_id']), 0, 64) : null;
            if ($postId !== null && $postId !== '' && $this->sensorRepo->existsByPostId($postId)) {
                $this->logger->info('PostData duplicate skipped post_id={pid}', ['pid' => $postId]);
                return ResponseHelper::textClose($response, 'Donnees deja enregistrees', 200);
            }
        }

        return parent::handle($request, $response);
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
            postId: $postId
        );
    }

    protected function insertData(object $data): void
    {
        $t0 = microtime(true);
        $this->sensorRepo->insert($data);
        $t1 = microtime(true);

        $this->outputRepo->syncStatesFromSensorData($data);
        $t2 = microtime(true);

        $this->boardRepo->updateLastRequest(TableConfig::getPostDataBoardId());
        $t3 = microtime(true);

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
