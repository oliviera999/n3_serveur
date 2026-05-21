<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Controller\AbstractPostDataController;
use App\Controller\Concerns\HmacAuthTrait;
use App\Domain\N3ppSensorData;
use App\Repository\N3ppSensorRepository;
use App\Service\LogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Reception des donnees POST du firmware n3pp (serre/aquaponie, board=3).
 * Herite du flux commun AbstractPostDataController.
 *
 * Authentification : HMAC-SHA256 si timestamp+signature presents, sinon clé API
 * legacy (compatibilite ascendante avec firmwares <= 4.38).
 */
class N3ppPostDataController extends AbstractPostDataController
{
    use HmacAuthTrait;

    public function __construct(
        LogService $logger,
        private N3ppSensorRepository $sensorRepo,
    ) {
        parent::__construct($logger);
    }

    protected function componentName(): string
    {
        return 'N3ppPostData';
    }

    /**
     * Auth HMAC optionnelle (timestamp+signature) avec fallback api_key.
     * Alignement contrat FFP3 (cf. App\Controller\Ffp3\PostDataController).
     */
    protected function validateAuth(Request $request, array $params, Response $response): ?Response
    {
        return $this->validateHmacOrFallback($params, $response);
    }

    protected function buildSensorData(array $params, \Closure $sanitize, \Closure $toFloat, \Closure $toInt): object
    {
        return new N3ppSensorData(
            sensor: substr($sanitize('sensor') ?? '', 0, 30),
            version: substr($sanitize('version') ?? '', 0, 30),
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
    }

    protected function insertData(object $data): void
    {
        $this->sensorRepo->insert($data);
    }
}
