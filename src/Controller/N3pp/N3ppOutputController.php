<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Config\N3ppGpioMap;
use App\Config\Version;
use App\Controller\AbstractOutputController;
use App\Notification\NotificationFamily;
use App\Repository\AbstractOutputRepository;
use App\Repository\N3ppOutputRepository;
use App\Repository\N3ppSensorRepository;
use App\Repository\NotificationPolicyRepository;
use App\Security\AuthService;
use App\Service\LogService;
use App\Service\NotificationPolicySaveService;
use App\Service\NotificationService;
use App\Service\OperationalSettingsService;
use App\Service\TemplateRenderer;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class N3ppOutputController extends AbstractOutputController
{
    public function __construct(
        LogService $logger,
        TemplateRenderer $renderer,
        AuthService $authService,
        private N3ppOutputRepository $outputRepo,
        private N3ppSensorRepository $sensorRepo,
        private NotificationPolicyRepository $notificationPolicyRepo,
        private NotificationPolicySaveService $notificationSaveService,
        private NotificationService $notificationService,
        ?OperationalSettingsService $operationalSettings = null,
    ) {
        parent::__construct($logger, $renderer, $authService, null, $operationalSettings);
    }

    protected function defaultBoard(): int
    {
        return 3;
    }
    protected function componentName(): string
    {
        return 'N3ppOutputController';
    }
    protected function controlTemplate(): string
    {
        return 'n3pp_control.twig';
    }

    protected function buildControlPageData(int $board): array
    {
        $envInfo = $this->resolveControlEnv('n3pp_test', 'n3pp');
        $env = $envInfo['env'];
        $outputsApiBase = $envInfo['outputs_api_base'];
        $realtimeApiBase = $envInfo['realtime_api_base'];

        try {
            $allOutputs = $this->outputRepo->getAllForBoard($board);
            $params = $this->outputRepo->getParametersForBoard($board);
            $resetOutput = $this->outputRepo->getOutputByGpioAndBoard($board, 110);
            $lastBoardRequest = $this->outputRepo->getLastBoardRequest($board);
            $firmwareVersion = $this->sensorRepo->getFirmwareVersion();
        } catch (\Throwable $e) {
            $this->logger->warning('N3ppOutputController: erreur lecture outputs (table manquante?) — {msg}', ['msg' => $e->getMessage()]);
            $allOutputs = [];
            $params = $this->getDefaultParams();
            $resetOutput = null;
            $lastBoardRequest = null;
            $firmwareVersion = 'N/A';
        }

        $actuatorOutputs = $this->filterActuatorOutputs($allOutputs);

        return array_merge([
            'page_title' => 'Contrôle serre / élevage - n3 iot',
            'outputs' => $actuatorOutputs,
            'params' => $params,
            'reset_output' => $resetOutput,
            'board' => $board,
            'last_board_request' => $lastBoardRequest,
            'version' => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment' => $env,
            'nav_active' => 'elevage_control',
            'outputs_api_base' => $outputsApiBase,
            'realtime_api_base' => $realtimeApiBase,
            'control_config' => $this->makeControlConfig(
                'n3pp_test',
                'Serre / Élevage',
                'Contrôle des sorties et paramètres de la serre et de l\'élevage d\'insectes (n3pp). L\'ESP32 applique les changements au prochain cycle (typ. FreqWakeUp secondes).',
                count($actuatorOutputs),
                'fa-seedling',
                'Contrôle de la serre',
                'Pilotez l\'eau (pompe, arrosage), l\'énergie et les alertes de la serre. Vos commandes sont transmises au module au prochain cycle (délai typique = Fréquence de réveil).',
                '/n3pp/api/outputs',
                'Ces commandes agissent sur du matériel réel (pompe d\'arrosage, relais). Vérifiez toujours sur place avant d\'activer une sortie.'
            ),
        ], $this->notificationPolicyTwigData($this->notificationPolicyRepo));
    }

    protected function notificationFamily(): NotificationFamily
    {
        return NotificationFamily::N3pp;
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

    protected function getDefaultParamKeys(): array
    {
        return ['mail', 'mailNotif', 'notifMode', 'notifCategories', 'SeuilSec', 'SeuilPontDiv', 'HeureArrosage', 'tempsArrosage', 'WakeUp', 'FreqWakeUp', 'veilleInfinie'];
    }

    protected function outputRepository(): AbstractOutputRepository
    {
        return $this->outputRepo;
    }

    protected function doToggle(array $params, int $board): array
    {
        $gpio = (int) ($params['gpio'] ?? 0);
        $state = (int) ($params['state'] ?? -1);

        if ($gpio <= 0) {
            return ['success' => false, 'error' => 'Paramètre gpio invalide', 'status' => 400];
        }
        if ($state !== 0 && $state !== 1) {
            return ['success' => false, 'error' => 'Paramètre state doit être 0 ou 1', 'status' => 400];
        }

        $stateStr = $state === 1 ? '1' : '0';
        $this->outputRepo->updateByGpio($gpio, $stateStr, $board);
        return ['success' => true, 'gpio' => $gpio, 'state' => $state];
    }

    protected function handleLegacyAction(Request $request, Response $response, string $action): Response
    {
        if ($action === 'output_update') {
            return $this->handleOutputUpdate($request, $response);
        }
        if ($action === 'output_delete') {
            return $this->handleOutputDelete($request, $response);
        }
        return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
    }

    protected function doSetOutput(array $params, int $board): array
    {
        $gpio = (int) ($params['gpio'] ?? 0);

        if ($gpio <= 0) {
            return ['success' => false, 'error' => 'Paramètre gpio invalide', 'status' => 400];
        }

        $state = $this->normalizeOutputState(trim((string) ($params['state'] ?? '0')));
        $this->outputRepo->updateByGpio($gpio, $state, $board);
        return ['success' => true, 'gpio' => $gpio, 'state' => $state];
    }

    private function handleOutputUpdate(Request $request, Response $response): Response
    {
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }
        $queryParams = $request->getQueryParams();
        $id = (int) ($queryParams['id'] ?? 0);
        $state = trim((string) ($queryParams['state'] ?? '0'));
        $state = in_array($state, ['0', '1'], true) ? $state : '0';

        if ($id <= 0) {
            return ResponseHelper::json($response, ['error' => 'Paramètre id invalide'], 400);
        }

        try {
            $this->outputRepo->updateById($id, $state);
            $this->logger->info('N3ppOutputController: output mis a jour par id', ['id' => $id, 'state' => $state]);
            return ResponseHelper::json($response, ['success' => true, 'id' => $id, 'state' => $state]);
        } catch (\Throwable $e) {
            $this->logger->error('N3ppOutputController: erreur updateById', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function handleOutputDelete(Request $request, Response $response): Response
    {
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }
        $queryParams = $request->getQueryParams();
        $id = (int) ($queryParams['id'] ?? 0);

        if ($id <= 0) {
            return ResponseHelper::json($response, ['error' => 'Paramètre id invalide'], 400);
        }

        try {
            $board = $this->outputRepo->deleteById($id);
            if ($board !== null) {
                $this->outputRepo->deleteBoardIfEmpty($board);
            }
            $this->logger->info('N3ppOutputController: output supprime', ['id' => $id]);
            return ResponseHelper::json($response, ['success' => true, 'id' => $id]);
        } catch (\Throwable $e) {
            $this->logger->error('N3ppOutputController: erreur deleteById', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    protected function allowedGpios(): array
    {
        $gpios = array_map('intval', array_keys(N3ppGpioMap::paramGpioMap()));
        foreach ([12, 13, 15, 16] as $actuator) {
            if (!in_array($actuator, $gpios, true)) {
                $gpios[] = $actuator;
            }
        }

        return $gpios;
    }
}
