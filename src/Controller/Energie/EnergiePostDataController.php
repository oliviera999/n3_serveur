<?php

declare(strict_types=1);

namespace App\Controller\Energie;

use App\Controller\AbstractHmacPostDataController;
use App\Domain\EnergieSensorData;
use App\Repository\EnergieSensorRepository;
use App\Service\HmacAuditLogger;
use App\Service\HmacPolicyService;
use App\Service\LogService;
use App\Service\OperationalSettingsService;

/**
 * Réception des agrégats POST du banc énergie (firmware `energie`, 3 × INA226),
 * routes `/energie/post-data` (prod) et `/energie-test/post-data` (energie_test).
 *
 * Même contrat d'authentification que MSP1/N3PP (AbstractHmacPostDataController +
 * HmacAuthTrait) : en-têtes `X-Sig-*` (body-signing), sinon `timestamp`+`signature`
 * dans le corps, sinon repli `api_key` — secrets partagés `API_KEY` / `API_SIG_SECRET`.
 *
 * Les dépendances optionnelles (audit HMAC, politique HMAC BDD, réglages opérationnels)
 * sont câblées explicitement dans config/dependencies.php : PHP-DI ne les autowire pas.
 * Contrat : docs/API_ENERGIE.md.
 */
class EnergiePostDataController extends AbstractHmacPostDataController
{
    public function __construct(
        LogService $logger,
        ?HmacAuditLogger $hmacAuditLogger,
        ?HmacPolicyService $hmacPolicyService,
        ?OperationalSettingsService $operationalSettings,
        private EnergieSensorRepository $sensorRepo,
    ) {
        parent::__construct($logger, $hmacAuditLogger, $hmacPolicyService, $operationalSettings);
    }

    protected function componentName(): string
    {
        return 'EnergiePostData';
    }

    protected function buildSensorData(array $params, \Closure $sanitize, \Closure $toFloat, \Closure $toInt): object
    {
        return new EnergieSensorData(
            sensor: substr($sanitize('sensor') ?? '', 0, 30),
            version: substr($sanitize('version') ?? '', 0, 30),
            panneauV: $toFloat('PanneauV'),
            panneauI: $toFloat('PanneauI'),
            panneauP: $toFloat('PanneauP'),
            panneauImax: $toFloat('PanneauImax'),
            batterieV: $toFloat('BatterieV'),
            batterieVmin: $toFloat('BatterieVmin'),
            batterieI: $toFloat('BatterieI'),
            batterieImin: $toFloat('BatterieImin'),
            batterieImax: $toFloat('BatterieImax'),
            batterieP: $toFloat('BatterieP'),
            batterieVadc: $toFloat('BatterieVadc'),
            consoV: $toFloat('ConsoV'),
            consoI: $toFloat('ConsoI'),
            consoP: $toFloat('ConsoP'),
            consoImax: $toFloat('ConsoImax'),
            energiePanneauWh: $toFloat('EnergiePanneauWh'),
            energieConsoWh: $toFloat('EnergieConsoWh'),
            batterieAh: $toFloat('BatterieAh'),
            batterieSoc: $toFloat('BatterieSoc'),
            inaStatus: $toInt('InaStatus'),
            i2cErreurs: $toInt('I2cErreurs'),
            rssi: $toInt('Rssi'),
            freeHeap: $toInt('FreeHeap'),
            bootCount: $toInt('BootCount'),
            uptime: $toInt('Uptime'),
        );
    }

    protected function insertData(object $data): void
    {
        if (!$data instanceof EnergieSensorData) {
            throw new \InvalidArgumentException('EnergieSensorData attendu, ' . $data::class . ' reçu');
        }
        $this->sensorRepo->insert($data);
    }
}
