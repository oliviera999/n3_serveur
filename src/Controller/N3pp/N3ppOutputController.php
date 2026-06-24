<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Config\TableConfig;
use App\Config\Version;
use App\Controller\AbstractOutputController;
use App\Notification\NotificationFamily;
use App\Repository\N3ppOutputRepository;
use App\Repository\N3ppSensorRepository;
use App\Repository\NotificationPolicyRepository;
use App\Security\AuthService;
use App\Service\LogService;
use App\Service\NotificationPolicySaveService;
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
    ) {
        parent::__construct($logger, $renderer, $authService);
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
        $env = TableConfig::getEnvironment();
        $isTest = $env === 'n3pp_test';
        $outputsApiBase = $isTest ? '/n3pp-test/api/outputs' : '/n3pp/api/outputs';
        $realtimeApiBase = $isTest ? '/n3pp-test/api/realtime' : '/n3pp/api/realtime';

        try {
            $partOutputs = $this->outputRepo->getPartOutputs($board, 3);
            $params = $this->outputRepo->getParametersForBoard($board);
            $resetOutput = $this->outputRepo->getOutputByGpioAndBoard($board, 110);
            $lastBoardRequest = $this->outputRepo->getLastBoardRequest($board);
            $firmwareVersion = $this->sensorRepo->getFirmwareVersion();
        } catch (\Throwable $e) {
            $this->logger->warning('N3ppOutputController: erreur lecture outputs (table manquante?) — {msg}', ['msg' => $e->getMessage()]);
            $partOutputs = [];
            $params = $this->getDefaultParams();
            $resetOutput = null;
            $lastBoardRequest = null;
            $firmwareVersion = 'N/A';
        }

        return array_merge([
            'page_title' => 'Contrôle serre / élevage - n3 iot',
            'part_outputs' => $partOutputs,
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
                'Contrôle des sorties et paramètres de la serre et de l\'élevage d\'insectes (n3pp). Les commandes sont transmises à l\'ESP32 au prochain cycle.',
                count($partOutputs),
                'fa-seedling',
                'Contrôle de la serre',
                'Pilotez l\'eau (pompe, arrosage), l\'énergie et les alertes de la serre. Vos commandes sont transmises au module au prochain cycle.',
                '/n3pp/api/outputs'
            ),
        ], $this->notificationPolicyTwigData($this->notificationPolicyRepo));
    }

    protected function notificationFamily(): NotificationFamily
    {
        return NotificationFamily::N3pp;
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
        return ['mail', 'mailNotif', 'notifMode', 'notifCategories', 'SeuilSec', 'SeuilPontDiv', 'HeureArrosage', 'tempsArrosage', 'WakeUp', 'FreqWakeUp'];
    }

    protected function getStateData(int $board): array
    {
        return $this->outputRepo->getStateForFirmware($board);
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
    protected function updateParameterByName(int $board, string $paramName, string $value): bool
    {
        return $this->outputRepo->updateParameterByName($board, $paramName, $value);
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

    protected function batchUpdateParameters(int $board, array $params): void
    {
        $this->outputRepo->batchUpdateParameters($board, $params);
    }
}
