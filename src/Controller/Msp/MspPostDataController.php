<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Controller\AbstractHmacPostDataController;
use App\Domain\MspSensorData;
use App\Repository\MspSensorRepository;
use App\Service\LogService;

/**
 * Reception des donnees POST du firmware msp (station meteo, board=2).
 * Herite du flux commun AbstractHmacPostDataController (auth HMAC + email).
 *
 * Authentification : HMAC-SHA256 si timestamp+signature presents, sinon clé API
 * legacy (compatibilite ascendante avec firmwares <= 2.42).
 */
class MspPostDataController extends AbstractHmacPostDataController
{
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

    protected function buildSensorData(array $params, \Closure $sanitize, \Closure $toFloat, \Closure $toInt): object
    {
        $mail = $this->sanitizeFirmwareEmail($params, $sanitize);

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
