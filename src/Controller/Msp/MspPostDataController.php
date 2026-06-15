<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Controller\AbstractPostDataController;
use App\Controller\Concerns\HmacAuthTrait;
use App\Domain\MspSensorData;
use App\Repository\MspSensorRepository;
use App\Service\LogService;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Reception des donnees POST du firmware msp (station meteo, board=2).
 * Herite du flux commun AbstractPostDataController.
 *
 * Authentification : HMAC-SHA256 si timestamp+signature presents, sinon clé API
 * legacy (compatibilite ascendante avec firmwares <= 2.42).
 */
class MspPostDataController extends AbstractPostDataController
{
    use HmacAuthTrait;

    public function __construct(
        LogService $logger,
        private MspSensorRepository $sensorRepo,
    ) {
        parent::__construct($logger);
    }

    protected function componentName(): string
    {
        return 'MspPostData';
    }

    /**
     * Auth HMAC optionnelle (timestamp+signature) avec fallback api_key.
     * Alignement contrat FFP3 (cf. App\Controller\Ffp3\PostDataController).
     */
    protected function validateAuth(array $params, Response $response): ?Response
    {
        return $this->validateHmacOrFallback($params, $response);
    }

    protected function buildSensorData(array $params, \Closure $sanitize, \Closure $toFloat, \Closure $toInt): object
    {
        $mail = $sanitize('mail');
        if ($mail !== null && $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning('MspPostData: email firmware invalide ignore (format)', [
                'sensor' => trim((string) ($params['sensor'] ?? '')),
                'version' => trim((string) ($params['version'] ?? '')),
                'mail_len' => strlen($mail),
            ]);
            $mail = '';
        }

        return new MspSensorData(
            sensor: substr($sanitize('sensor') ?? '', 0, 30),
            version: substr($sanitize('version') ?? '', 0, 30),
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
            mail: $mail,
            mailNotif: $sanitize('mailNotif'),
            resetMode: $toInt('resetMode'),
            bootCount: $toInt('bootCount'),
        );
    }

    protected function insertData(object $data): void
    {
        $this->sensorRepo->insert($data);
    }
}
