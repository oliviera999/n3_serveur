<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Domain\MspSensorData;
use App\Repository\MspSensorRepository;
use App\Service\LogService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Reception des donnees POST du firmware msp2_5 (station meteo).
 * Compatibilite totale avec le contrat d'interface existant :
 *   POST /msp1/msp1datas/post-msp1-data.php
 *   Content-Type: application/x-www-form-urlencoded
 */
class MspPostDataController
{
    public function __construct(
        private LogService $logger,
        private MspSensorRepository $sensorRepo,
    ) {
    }

    public function handle(Request $request, Response $response): Response
    {
        set_time_limit(30);

        if ($request->getMethod() !== 'POST') {
            return ResponseHelper::text($response, 'Methode non autorisee', 405);
        }

        $params = $request->getParsedBody();
        if (!is_array($params)) {
            $params = [];
        }

        // Validation cle API (mode legacy, pas de HMAC pour les firmwares simples)
        $apiKeyProvided = $params['api_key'] ?? '';
        $apiKeyExpected = $_ENV['API_KEY'] ?? null;

        if ($apiKeyExpected === null) {
            $this->logger->error('MspPostData: Variable API_KEY manquante dans .env');
            return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
        }

        if ($apiKeyProvided !== $apiKeyExpected) {
            $this->logger->warning('MspPostData: Cle API invalide', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
            return ResponseHelper::text($response, 'Cle API incorrecte', 401);
        }

        $sanitize = static function (string $key) use ($params): ?string {
            if (!isset($params[$key]) || !is_scalar($params[$key])) {
                return null;
            }
            $v = trim((string) $params[$key]);
            return $v !== '' ? $v : null;
        };
        $toFloat = static function (string $key) use ($params): ?float {
            if (!isset($params[$key]) || !is_scalar($params[$key]) || $params[$key] === '') {
                return null;
            }
            $f = (float) $params[$key];
            return is_finite($f) ? $f : null;
        };
        $toInt = static fn(string $key) => isset($params[$key]) && is_scalar($params[$key]) && $params[$key] !== ''
            ? (int) $params[$key] : null;

        $sensor = $sanitize('sensor');
        $version = $sanitize('version');
        if ($sensor === null || $version === null) {
            return ResponseHelper::text($response, 'Champs requis manquants: sensor, version', 400);
        }

        $data = new MspSensorData(
            sensor: substr($sensor, 0, 30),
            version: substr($version, 0, 30),
            tempAirInt: $toFloat('TempAirInt'),
            tempAirExt: $toFloat('TempAirExt'),
            humidAirInt: $toFloat('HumidAirInt'),
            humidAirExt: $toFloat('HumidAirExt'),
            luminositeA: $toFloat('LuminositeA'),
            luminositeB: $toFloat('LuminositeB'),
            luminositeC: $toFloat('LuminositeC'),
            luminositeD: $toFloat('LuminositeD'),
            luminositeMoy: $toFloat('LuminositeMoy'),
            servoHB: $toInt('ServoHB'),
            servoGD: $toInt('ServoGD'),
            humidSol: $toFloat('HumidSol'),
            pluie: $toFloat('Pluie'),
            tempEau: $toFloat('TempEau'),
            pontDiv: $toInt('PontDiv'),
            wakeUp: $toInt('WakeUp'),
            seuilSec: $toInt('SeuilSec'),
            freqWakeUp: $toInt('FreqWakeUp'),
            seuilPontDiv: $toInt('SeuilPontDiv'),
            mail: $sanitize('mail'),
            mailNotif: $sanitize('mailNotif'),
            resetMode: $toInt('resetMode'),
            bootCount: $toInt('bootCount'),
        );

        try {
            $this->sensorRepo->insert($data);
            $this->logger->info('MspPostData OK sensor={sensor} version={version}', [
                'sensor' => $data->sensor,
                'version' => $data->version,
            ]);
            return ResponseHelper::textClose($response, 'Donnees enregistrees avec succes', 200);
        } catch (Throwable $e) {
            $this->logger->error('MspPostData: Erreur insertion: {error}', ['error' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }
}
