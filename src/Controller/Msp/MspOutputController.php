<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Config\MspGpioMap;
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
use App\Service\NotificationService;
use App\Service\OperationalSettingsService;
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
        private NotificationPolicySaveService $notificationSaveService,
        private NotificationService $notificationService,
        ?OperationalSettingsService $operationalSettings = null,
    ) {
        parent::__construct($logger, $renderer, $authService, null, $operationalSettings);
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
        $outputsApiBase = $isTest ? '/msp1-test/api/outputs' : '/msp1/api/outputs';
        $realtimeApiBase = $isTest ? '/msp1-test/api/realtime' : '/msp1/api/realtime';

        try {
            $outputs = $this->outputRepo->getAllForBoard($board);
            $params = $this->outputRepo->getParametersForBoard($board);
            $resetOutput = $this->outputRepo->getOutputByGpioAndBoard($board, 110);
            $lastBoardRequest = $this->outputRepo->getLastBoardRequest($board);
        } catch (\Throwable $e) {
            $this->logger->warning('MspOutputController: erreur lecture outputs (table manquante?) — {msg}', ['msg' => $e->getMessage()]);
            $outputs = [];
            $params = $this->getDefaultParams();
            $resetOutput = null;
            $lastBoardRequest = null;
        }

        $actuatorCount = count(array_filter(
            $outputs,
            static fn (array $output): bool => (int) ($output['gpio'] ?? 0) < 100
        ));

        return array_merge([
            'page_title' => 'Contrôle station météo - Le potager',
            'outputs' => $outputs,
            'params' => $params,
            'reset_output' => $resetOutput,
            'board' => $board,
            'last_board_request' => $lastBoardRequest,
            'version' => Version::getWithPrefix(),
            'firmware_version' => $this->sensorRepo->getFirmwareVersion(),
            'environment' => $env,
            'nav_active' => 'potager_control',
            'outputs_api_base' => $outputsApiBase,
            'realtime_api_base' => $realtimeApiBase,
            'control_config' => $this->makeControlConfig(
                'msp_test',
                'Station Météo',
                'Contrôle des paramètres de la station météo (MSP). L\'ESP32 applique les changements au prochain cycle (typ. FreqWakeUp secondes).',
                $actuatorCount,
                'fa-cloud-sun',
                'Contrôle de la station météo',
                'Réglez les servos, la veille et les alertes. Vos commandes sont transmises au module au prochain cycle (délai typique = Fréquence de réveil).',
                '/msp1/api/outputs',
                'Ces commandes agissent sur du matériel réel (servos, tracker solaire). Vérifiez toujours sur place avant de modifier les angles.'
            ),
        ], $this->notificationPolicyTwigData($this->notificationPolicyRepo));
    }

    protected function notificationFamily(): NotificationFamily
    {
        return NotificationFamily::Msp1;
    }

    protected function notificationSaveService(): NotificationPolicySaveService
    {
        return $this->notificationSaveService;
    }

    protected function notificationService(): NotificationService
    {
        return $this->notificationService;
    }

    protected function notificationPolicyRepository(): NotificationPolicyRepository
    {
        return $this->notificationPolicyRepo;
    }

    public function saveNotificationPolicy(Request $request, Response $response): Response
    {
        return $this->updateNotificationPolicy($request, $response);
    }

    protected function getDefaultParamKeys(): array
    {
        return ['mail', 'mailNotif', 'notifMode', 'notifCategories', 'SeuilSec', 'SeuilPontDiv', 'ServoHB', 'ServoGD', 'WakeUp', 'FreqWakeUp', 'ServoModeAuto', 'veilleInfinie'];
    }

    /** @return array<string, string> */
    protected function getDefaultParams(): array
    {
        $params = parent::getDefaultParams();
        $params['ServoModeAuto'] = '1';
        // Veille infinie sous seuil batterie : active par defaut (comportement
        // historique du firmware). GPIO virtuel 112.
        $params['veilleInfinie'] = '1';
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

    protected function allowedGpios(): array
    {
        return array_map('intval', array_keys(MspGpioMap::paramGpioMap()));
    }
}
