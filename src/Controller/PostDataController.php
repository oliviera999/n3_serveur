<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Domain\SensorData;
use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorRepository;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use App\Service\OutputCacheService;
use App\Security\SignatureValidator;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

class PostDataController
{
    public function __construct(
        private LogService $logger,
        private ErrorAlertService $errorAlert,
        private OutputCacheService $outputCache,
        private SensorRepository $sensorRepo,
        private OutputRepository $outputRepo,
        private BoardRepository $boardRepo
    ) {
    }

    /**
     * Point d'entrée HTTP : /post-data (méthode POST)
     * Vérifie la clé API, construit l'objet SensorData et insère la ligne.
     */
    public function handle(Request $request, Response $response): Response
    {
        // Client ESP32 timeout = 18–26 s (config firmware). Laisser marge côté serveur pour répondre avant coupure.
        set_time_limit(30);

        // Diagnostic latence : heure de réception de la requête (corrélation avec log ESP32)
        $tReceived = microtime(true);
        $sec = (int) $tReceived;
        $us = (int) (($tReceived - $sec) * 1000000);
        $this->logger->info(
            'PostData request received at={at} ts={ts}',
            ['at' => date('Y-m-d H:i:s', $sec) . '.' . sprintf('%06d', $us), 'ts' => round($tReceived, 3)]
        );

        // Vérifier méthode POST
        if ($request->getMethod() !== 'POST') {
            $this->logger->warning('PostData: Méthode non autorisée', ['method' => $request->getMethod()]);
            return ResponseHelper::text($response, 'Méthode non autorisée', 405);
        }

        $params = $request->getParsedBody();
        if (!is_array($params)) {
            $params = [];
        }

        // ---------------------------------------------------------------------
        // Validation de la signature HMAC : facultative.
        // Si timestamp ET signature sont fournis => on valide.
        // Sinon, on laisse passer mais on loggue l'absence.
        // ---------------------------------------------------------------------
        $timestamp = $params['timestamp'] ?? null;
        $signature = $params['signature'] ?? null;

        if ($timestamp !== null || $signature !== null) {
            // Au moins un des deux champs est présent : on exige les deux + validation
            if ($timestamp === null || $signature === null) {
                $this->logger->warning('Signature partielle reçue mais incomplète', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
                return ResponseHelper::text($response, 'Signature incomplète', 401);
            }

            $sigSecret = $_ENV['API_SIG_SECRET'] ?? null;
            if ($sigSecret === null) {
                $errorMessage = 'Variable API_SIG_SECRET manquante dans .env';
                $this->logger->error($errorMessage);
                $this->errorAlert->recordError($errorMessage);
                return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
            }

            $sigWindow = (int) ($_ENV['SIG_VALID_WINDOW'] ?? 300);

            if (!SignatureValidator::isValid((string) $timestamp, (string) $signature, $sigSecret, $sigWindow)) {
                $this->logger->warning('Signature HMAC invalide', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
                return ResponseHelper::text($response, 'Signature incorrecte', 401);
            }
            // Signature OK
        } else {
            // Pas de signature → mode compatibilité
            $this->logger->info('Aucune signature fournie – fallback API_KEY');
        }

        // ---------------------------------------------------------------------
        // Validation de la clé API (mécanisme legacy)
        // ---------------------------------------------------------------------
        $apiKeyProvided = $params['api_key'] ?? '';
        $apiKeyExpected = $_ENV['API_KEY'] ?? null;

        if ($apiKeyExpected === null) {
            $errorMessage = 'Variable API_KEY manquante dans .env';
            $this->logger->error($errorMessage);
            $this->errorAlert->recordError($errorMessage);
            return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
        }

        if ($apiKeyProvided !== $apiKeyExpected) {
            $this->logger->warning("Clé API invalide depuis {ip}", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
            return ResponseHelper::text($response, 'Clé API incorrecte', 401);
        }

        // Fonctions utilitaires de lecture POST --------------------------------
        // Valeur brute (chaîne) ou null si absente / vide / non-scalaire
        $sanitize = static function (string $key) use ($params): ?string {
            if (!isset($params[$key]) || !is_scalar($params[$key])) {
                return null;
            }
            $v = trim((string) $params[$key]);
            return $v !== '' ? $v : null;
        };
        // Conversions typées sûres (retournent null si champ manquant ou invalide)
        $toFloat = static function (string $key) use ($params): ?float {
            if (!isset($params[$key]) || !is_scalar($params[$key]) || $params[$key] === '') {
                return null;
            }
            $f = (float) $params[$key];
            return is_finite($f) ? $f : null;
        };
        $toInt = static fn(string $key) => isset($params[$key]) && is_scalar($params[$key]) && $params[$key] !== ''
            ? (int) $params[$key] : null;

        // Validation des champs requis (BDD : sensor et version NOT NULL)
        $sensor = $sanitize('sensor');
        $version = $sanitize('version');
        if ($sensor === null || $version === null) {
            $missing = array_filter([
                $sensor === null ? 'sensor' : null,
                $version === null ? 'version' : null,
            ]);
            $msg = 'Champs requis manquants: ' . implode(', ', $missing);
            $this->logger->warning('PostData: ' . $msg, ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
            return ResponseHelper::text($response, $msg, 400);
        }
        // Limiter à 30 caractères (taille colonne BDD)
        $sensor = substr($sensor, 0, 30);
        $version = substr($version, 0, 30);

        // Limiter mail/mailNotif à 255 caractères (évite erreur BDD si colonnes VARCHAR(255))
        $mailMaxLen = 255;
        $mail = $sanitize('mail');
        $mail = $mail !== null ? substr($mail, 0, $mailMaxLen) : null;
        $mailNotif = $sanitize('mailNotif');
        $mailNotif = $mailNotif !== null ? substr($mailNotif, 0, $mailMaxLen) : null;

        // Déduplication par post_id : si fourni et déjà inséré, retourner 200 sans doublon
        $postId = $sanitize('post_id');
        if ($postId !== null) {
            $postId = substr($postId, 0, 64);
            if ($this->sensorRepo->existsByPostId($postId)) {
                $this->logger->info('PostData duplicate skipped post_id={pid}', ['pid' => $postId]);
                return ResponseHelper::textClose($response, 'Données déjà enregistrées', 200);
            }
        }

        // Construction de l'objet transférant les données capteurs -------------
        $data = new SensorData(
            sensor: $sensor,
            version: $version,
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

        try {
            $t0 = microtime(true);

            // Insertion des données capteurs via le repository injecté
            $this->sensorRepo->insert($data);
            $t1 = microtime(true);

            // Synchroniser les états dans ffp3Outputs/ffp3Outputs2
            $this->outputRepo->syncStatesFromSensorData($data);
            $t2 = microtime(true);

            // Invalider le cache après synchronisation ESP32
            $this->outputCache->invalidateCache();
            $t3 = microtime(true);

            // Mettre à jour le timestamp de la dernière requête de la board
            $boardId = TableConfig::getPostDataBoardId();
            $this->boardRepo->updateLastRequest($boardId);
            $t4 = microtime(true);

            $insertMs = round(($t1 - $t0) * 1000);
            $syncMs = round(($t2 - $t1) * 1000);
            $cacheMs = round(($t3 - $t2) * 1000);
            $boardMs = round(($t4 - $t3) * 1000);
            $totalMs = round(($t4 - $t0) * 1000);

            $this->logger->info(
                'PostData OK sensor={sensor} version={version} timing_ms: insert={insertMs} sync={syncMs} cache={cacheMs} board={boardMs} total={totalMs}',
                [
                    'sensor' => $data->sensor,
                    'version' => $data->version,
                    'insertMs' => $insertMs,
                    'syncMs' => $syncMs,
                    'cacheMs' => $cacheMs,
                    'boardMs' => $boardMs,
                    'totalMs' => $totalMs,
                ]
            );

            return ResponseHelper::textClose($response, 'Données enregistrées avec succès', 200);
            
        } catch (Throwable $e) {
            $errorMessage = 'Erreur insertion données';
            $this->logger->error($errorMessage, ['error' => $e->getMessage()]);
            
            // Enregistrer l'erreur pour détection répétée
            $this->errorAlert->recordError($errorMessage, [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }
}
