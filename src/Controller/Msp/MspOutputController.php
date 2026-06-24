<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Config\TableConfig;
use App\Config\Version;
use App\Controller\AbstractOutputController;
use App\Notification\NotificationFamily;
use App\Repository\MspOutputRepository;
use App\Repository\MspSensorRepository;
use App\Repository\NotificationPolicyRepository;
use App\Security\AuthService;
use App\Service\LogService;
use App\Service\NotificationPolicySaveService;
use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MspOutputController extends AbstractOutputController
{
    public function __construct(
        LogService $logger,
        TemplateRenderer $renderer,
        AuthService $authService,
        private MspOutputRepository $outputRepo,
        private MspSensorRepository $sensorRepo,
        private NotificationPolicyRepository $notificationPolicyRepo,
    ) {
        parent::__construct($logger, $renderer, $authService);
    }

    protected function defaultBoard(): int
    {
        return 2;
    }
    protected function componentName(): string
    {
        return 'MspOutputController';
    }
    protected function controlTemplate(): string
    {
        return 'msp1_control.twig';
    }

    protected function buildControlPageData(int $board): array
    {
        $env = TableConfig::getEnvironment();
        $isTest = $env === 'msp_test';
        $outputs = $this->outputRepo->getAllForBoard($board);
        $outputsApiBase = $isTest ? '/msp1-test/api/outputs' : '/msp1/api/outputs';
        $realtimeApiBase = $isTest ? '/msp1-test/api/realtime' : '/msp1/api/realtime';

        try {
            $params = $this->outputRepo->getParametersForBoard($board);
            $resetOutput = $this->outputRepo->getOutputByGpioAndBoard($board, 110);
        } catch (\Throwable $e) {
            $this->logger->warning('MspOutputController: erreur lecture params (table manquante?) — {msg}', ['msg' => $e->getMessage()]);
            $params = $this->getDefaultParams();
            $resetOutput = null;
        }

        return array_merge([
            'page_title' => 'Contrôle station météo - Le potager',
            'outputs' => $outputs,
            'params' => $params,
            'reset_output' => $resetOutput,
            'board' => $board,
            'last_board_request' => $this->outputRepo->getLastBoardRequest($board),
            'version' => Version::getWithPrefix(),
            'firmware_version' => $this->sensorRepo->getFirmwareVersion(),
            'environment' => $env,
            'nav_active' => 'potager_control',
            'outputs_api_base' => $outputsApiBase,
            'realtime_api_base' => $realtimeApiBase,
            'control_config' => $this->makeControlConfig(
                'msp_test',
                'Station Météo',
                'Contrôle des sorties et paramètres de la station météo (MSP). Les commandes sont transmises à l\'ESP32 au prochain cycle.',
                count($outputs),
                'fa-cloud-sun',
                'Contrôle de la station météo',
                'Activez ou coupez les sorties, et réglez les paramètres du potager. Vos commandes sont envoyées au module au cycle suivant — elles ne sont donc pas instantanées.',
                '/msp1/api/outputs'
            ),
        ], $this->notificationPolicyTwigData($this->notificationPolicyRepo));
    }

    protected function notificationFamily(): NotificationFamily
    {
        return NotificationFamily::Msp1;
    }

    public function saveNotificationPolicy(
        Request $request,
        Response $response,
        NotificationPolicySaveService $saveService
    ): Response {
        return $this->updateNotificationPolicy(
            $request,
            $response,
            $saveService,
            $this->notificationPolicyRepo
        );
    }

    protected function getDefaultParamKeys(): array
    {
        return ['mail', 'mailNotif', 'notifMode', 'notifCategories', 'SeuilSec', 'SeuilPontDiv', 'ServoHB', 'ServoGD', 'WakeUp', 'FreqWakeUp', 'ServoModeAuto'];
    }

    /** @return array<string, string> */
    protected function getDefaultParams(): array
    {
        $params = parent::getDefaultParams();
        $params['ServoModeAuto'] = '1';
        return $params;
    }

    protected function getStateData(int $board): array
    {
        return $this->outputRepo->getStateForFirmware($board);
    }

    protected function doToggle(array $params, int $board): array
    {
        $gpio = (int) ($params['gpio'] ?? 0);
        $name = trim((string) ($params['name'] ?? ''));
        $state = (int) ($params['state'] ?? -1);

        if ($state !== 0 && $state !== 1) {
            return ['success' => false, 'error' => 'Paramètre state doit être 0 ou 1', 'status' => 400];
        }

        $stateStr = $state === 1 ? '1' : '0';

        if ($gpio > 0) {
            $this->outputRepo->updateByGpio($gpio, $stateStr, $board);
            return ['success' => true, 'gpio' => $gpio, 'state' => $state];
        }
        if ($name !== '') {
            $this->outputRepo->updateByName($name, $stateStr, $board);
            return ['success' => true, 'name' => $name, 'state' => $state];
        }

        return ['success' => false, 'error' => 'Paramètre gpio ou name requis', 'status' => 400];
    }

    protected function doSetOutput(array $params, int $board): array
    {
        $gpio = (int) ($params['gpio'] ?? 0);
        $name = trim((string) ($params['name'] ?? ''));
        $state = $this->normalizeOutputState(trim((string) ($params['state'] ?? '0')));

        if ($gpio > 0) {
            $this->outputRepo->updateByGpio($gpio, $state, $board);
            return ['success' => true, 'gpio' => $gpio, 'state' => $state];
        }
        if ($name !== '') {
            $this->outputRepo->updateByName($name, $state, $board);
            return ['success' => true, 'name' => $name, 'state' => $state];
        }

        return ['success' => false, 'error' => 'Paramètre gpio ou name requis', 'status' => 400];
    }
    protected function updateParameterByName(int $board, string $paramName, string $value): bool
    {
        return $this->outputRepo->updateParameterByName($board, $paramName, $value);
    }

    protected function batchUpdateParameters(int $board, array $params): void
    {
        $this->outputRepo->batchUpdateParameters($board, $params);
    }
}
