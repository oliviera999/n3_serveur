<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Controller\AbstractPostDataController;
use App\Domain\MspSensorData;
use App\Repository\MspSensorRepository;
use App\Service\LogService;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Reception des donnees POST du firmware msp2_5 (station meteo).
 * Herite du flux commun AbstractPostDataController.
 */
class MspPostDataController extends AbstractPostDataController
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
            mail: $sanitize('mail'),
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
