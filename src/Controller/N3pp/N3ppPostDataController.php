<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Domain\N3ppSensorData;
use App\Repository\N3ppSensorRepository;
use App\Service\LogService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Reception des donnees POST du firmware n3pp4_2 (serre/aquaponie).
 * Compatibilite totale avec le contrat d'interface existant :
 *   POST /n3pp/n3ppdatas/post-n3pp-data.php
 *   Content-Type: application/x-www-form-urlencoded
 */
class N3ppPostDataController
{
    public function __construct(
        private LogService $logger,
        private N3ppSensorRepository $sensorRepo,
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

        $apiKeyProvided = $params['api_key'] ?? '';
        $apiKeyExpected = $_ENV['API_KEY'] ?? null;

        if ($apiKeyExpected === null) {
            $this->logger->error('N3ppPostData: Variable API_KEY manquante dans .env');
            return ResponseHelper::text($response, 'Configuration serveur manquante', 500);
        }

        if ($apiKeyProvided !== $apiKeyExpected) {
            $this->logger->warning('N3ppPostData: Cle API invalide', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a']);
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

        $data = new N3ppSensorData(
            sensor: substr($sensor, 0, 30),
            version: substr($version, 0, 30),
            tempAir: $toFloat('TempAir'),
            humidite: $toFloat('Humidite'),
            luminosite: $toFloat('Luminosite'),
            humid1: $toFloat('Humid1'),
            humid2: $toFloat('Humid2'),
            humid3: $toFloat('Humid3'),
            humid4: $toFloat('Humid4'),
            humidMoy: $toFloat('HumidMoy'),
            pontDiv: $toInt('PontDiv'),
            wakeUp: $toInt('WakeUp'),
            arrosageManu: $toInt('ArrosageManu'),
            seuilSec: $toInt('SeuilSec'),
            freqWakeUp: $toInt('FreqWakeUp'),
            seuilPontDiv: $toInt('SeuilPontDiv'),
            mail: $sanitize('mail'),
            mailNotif: $sanitize('mailNotif'),
            heureArrosage: $toInt('HeureArrosage'),
            resetMode: $toInt('resetMode'),
            etatPompe: $toInt('etatPompe'),
            tempsArrosage: $toInt('tempsArrosage'),
            bootCount: $toInt('bootCount'),
        );

        try {
            $this->sensorRepo->insert($data);
            $this->logger->info('N3ppPostData OK sensor={sensor} version={version}', [
                'sensor' => $data->sensor,
                'version' => $data->version,
            ]);
            return ResponseHelper::textClose($response, 'Donnees enregistrees avec succes', 200);
        } catch (Throwable $e) {
            $this->logger->error('N3ppPostData: Erreur insertion: {error}', ['error' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }
}
