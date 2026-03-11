<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Controller\AbstractOutputController;
use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\MspOutputRepository;
use App\Repository\MspSensorRepository;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MspOutputController extends AbstractOutputController
{
    public function __construct(
        LogService $logger,
        TemplateRenderer $renderer,
        private MspOutputRepository $outputRepo,
        private MspSensorRepository $sensorRepo,
    ) {
        parent::__construct($logger, $renderer);
    }

    protected function defaultBoard(): int { return 2; }
    protected function componentName(): string { return 'MspOutputController'; }
    protected function controlTemplate(): string { return 'msp1_control.twig'; }

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

        return [
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
                'Contrôle MSP1 – Station Météo',
                'Activez/désactivez les sorties et configurez les paramètres du firmware msp2_5.',
                '/msp1/api/outputs'
            ),
        ];
    }

    /** @return array<string, string> Paramètres par défaut quand la table outputs est indisponible */
    private function getDefaultParams(): array
    {
        return [
            'mail' => '',
            'mailNotif' => '',
            'SeuilSec' => '0',
            'SeuilPontDiv' => '0',
            'ServoHB' => '0',
            'ServoGD' => '0',
            'WakeUp' => '0',
            'FreqWakeUp' => '0',
        ];
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

    /**
     * POST legacy /msp1/msp1control/msp1-outputs-action.php
     */
    public function setOutput(Request $request, Response $response): Response
    {
        $params = $request->getMethod() === 'POST' ? $request->getParsedBody() ?? [] : $request->getQueryParams();
        $action = $params['action'] ?? '';

        if ($action === 'output_create') {
            return $this->handleOutputCreate($request, $response);
        }
        if ($action !== 'set') {
            return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
        }

        $gpio = (int) ($params['gpio'] ?? 0);
        $name = trim((string) ($params['name'] ?? ''));
        $state = trim((string) ($params['state'] ?? '0'));
        $board = (int) ($params['board'] ?? $this->defaultBoard());

        $state = in_array($state, ['0', '1', '1.00'], true) ? '1' : '0';

        if ($gpio > 0) {
            try {
                $this->outputRepo->updateByGpio($gpio, $state, $board);
                $this->logger->info('MspOutputController: output mis a jour', ['gpio' => $gpio, 'state' => $state, 'board' => $board]);
                return ResponseHelper::json($response, ['success' => true, 'gpio' => $gpio, 'state' => $state]);
            } catch (\Throwable $e) {
                $this->logger->error('MspOutputController: erreur mise a jour', ['error' => $e->getMessage()]);
                return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
            }
        }
        if ($name !== '') {
            try {
                $this->outputRepo->updateByName($name, $state, $board);
                $this->logger->info('MspOutputController: output mis a jour', ['name' => $name, 'state' => $state, 'board' => $board]);
                return ResponseHelper::json($response, ['success' => true, 'name' => $name, 'state' => $state]);
            } catch (\Throwable $e) {
                $this->logger->error('MspOutputController: erreur mise a jour', ['error' => $e->getMessage()]);
                return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
            }
        }

        return ResponseHelper::json($response, ['error' => 'Paramètre gpio ou name requis'], 400);
    }

    /**
     * API: Met a jour un parametre.
     */
    public function updateParameters(Request $request, Response $response): Response
    {
        $payload = RequestHelper::extractParams($request);
        if (isset($payload['param'])) {
            $payload = [$payload['param'] => $payload['value'] ?? null];
        }
        if (!is_array($payload) || $payload === []) {
            return ResponseHelper::json($response, ['error' => 'Paramètre manquant'], 400);
        }

        $board = $this->defaultBoard();
        $paramName = (string) array_key_first($payload);
        $value = $payload[$paramName];
        if ($value === null) {
            $value = '';
        }
        $value = trim((string) $value);

        if ($paramName === 'mailNotif') {
            $value = in_array(strtolower($value), ['1', 'true', 'checked', 'on', 'oui'], true) ? 'checked' : 'false';
        }
        if ($paramName === 'WakeUp') {
            $value = in_array($value, ['1', 'true', 'on'], true) ? '1' : '0';
        }

        try {
            $ok = $this->outputRepo->updateParameterByName($board, $paramName, $value);
            if (!$ok) {
                return ResponseHelper::json($response, ['error' => 'Paramètre inconnu'], 400);
            }
            $this->logger->info('MspOutputController: parametre mis a jour', ['param' => $paramName]);
            return ResponseHelper::json($response, ['success' => true, 'param' => $paramName]);
        } catch (\Throwable $e) {
            $this->logger->error('MspOutputController: erreur updateParameterByName', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function handleOutputCreate(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $board = $this->defaultBoard();
        $params = [
            'mail' => isset($body['mail']) ? trim((string) $body['mail']) : '',
            'mailNotif' => isset($body['mailNotif']) ? trim((string) $body['mailNotif']) : 'false',
            'SeuilSec' => isset($body['SeuilSec']) ? trim((string) $body['SeuilSec']) : '0',
            'SeuilPontDiv' => isset($body['SeuilPontDiv']) ? trim((string) $body['SeuilPontDiv']) : '0',
            'ServoHB' => isset($body['ServoHB']) ? trim((string) $body['ServoHB']) : '0',
            'ServoGD' => isset($body['ServoGD']) ? trim((string) $body['ServoGD']) : '0',
            'WakeUp' => isset($body['WakeUp']) ? trim((string) $body['WakeUp']) : '0',
            'FreqWakeUp' => isset($body['FreqWakeUp']) ? trim((string) $body['FreqWakeUp']) : '0',
        ];

        try {
            $this->outputRepo->batchUpdateParameters($board, $params);
            $this->logger->info('MspOutputController: parametres mis a jour (output_create)', ['board' => $board]);
            return ResponseHelper::json($response, ['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('MspOutputController: erreur batchUpdateParameters', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }
}
