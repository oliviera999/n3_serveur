<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Controller\AbstractOutputController;
use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\N3ppOutputRepository;
use App\Repository\N3ppSensorRepository;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class N3ppOutputController extends AbstractOutputController
{
    public function __construct(
        LogService $logger,
        TemplateRenderer $renderer,
        private N3ppOutputRepository $outputRepo,
        private N3ppSensorRepository $sensorRepo,
    ) {
        parent::__construct($logger, $renderer);
    }

    protected function defaultBoard(): int { return 3; }
    protected function componentName(): string { return 'N3ppOutputController'; }
    protected function controlTemplate(): string { return 'n3pp_control.twig'; }

    protected function buildControlPageData(int $board): array
    {
        $env = TableConfig::getEnvironment();
        $isTest = $env === 'n3pp_test';
        $partOutputs = $this->outputRepo->getPartOutputs($board, 3);
        return [
            'page_title' => 'Contrôle serre / élevage - n3 iot',
            'part_outputs' => $partOutputs,
            'params' => $this->outputRepo->getParametersForBoard($board),
            'reset_output' => $this->outputRepo->getOutputByGpioAndBoard($board, 110),
            'board' => $board,
            'last_board_request' => $this->outputRepo->getLastBoardRequest($board),
            'version' => Version::getWithPrefix(),
            'firmware_version' => $this->sensorRepo->getFirmwareVersion(),
            'environment' => $env,
            'nav_active' => 'elevage_control',
            'outputs_api_base' => $isTest ? '/n3pp-test/api/outputs' : '/n3pp/api/outputs',
            'realtime_api_base' => $isTest ? '/n3pp-test/api/realtime' : '/n3pp/api/realtime',
            'control_config' => [
                'test_env' => 'n3pp_test',
                'sidebar_title' => 'Serre / Élevage',
                'sidebar_description' => 'Contrôle des sorties et paramètres de la serre et de l\'élevage d\'insectes (n3pp). Les commandes sont transmises à l\'ESP32 au prochain cycle.',
                'outputs_count' => count($partOutputs),
                'icon' => 'fa-seedling',
                'main_title' => 'Contrôle N3PP – Serre & Élevage',
                'main_description' => 'Activez/désactivez les sorties et configurez les paramètres du firmware n3pp4_2 (arrosage, énergie, notifications).',
                'default_api_base' => '/n3pp/api/outputs',
            ],
        ];
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

    /**
     * POST legacy /n3pp/n3ppcontrol/n3pp-outputs-action.php
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
        $state = trim((string) ($params['state'] ?? '0'));
        $board = (int) ($params['board'] ?? $this->defaultBoard());

        if ($gpio <= 0) {
            return ResponseHelper::json($response, ['error' => 'Paramètre gpio invalide'], 400);
        }

        $state = in_array($state, ['0', '1', '1.00'], true) ? '1' : '0';

        try {
            $this->outputRepo->updateByGpio($gpio, $state, $board);
            $this->logger->info('N3ppOutputController: output mis a jour', ['gpio' => $gpio, 'state' => $state, 'board' => $board]);
            return ResponseHelper::json($response, ['success' => true, 'gpio' => $gpio, 'state' => $state]);
        } catch (\Throwable $e) {
            $this->logger->error('N3ppOutputController: erreur mise a jour', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
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
            $this->logger->info('N3ppOutputController: parametre mis a jour', ['param' => $paramName]);
            return ResponseHelper::json($response, ['success' => true, 'param' => $paramName]);
        } catch (\Throwable $e) {
            $this->logger->error('N3ppOutputController: erreur updateParameterByName', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function handleOutputUpdate(Request $request, Response $response): Response
    {
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

    private function handleOutputCreate(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $board = $this->defaultBoard();
        $params = [
            'mail' => isset($body['mail']) ? trim((string) $body['mail']) : '',
            'mailNotif' => isset($body['mailNotif']) ? trim((string) $body['mailNotif']) : 'false',
            'SeuilSec' => isset($body['SeuilSec']) ? trim((string) $body['SeuilSec']) : '0',
            'SeuilPontDiv' => isset($body['SeuilPontDiv']) ? trim((string) $body['SeuilPontDiv']) : '0',
            'HeureArrosage' => isset($body['HeureArrosage']) ? trim((string) $body['HeureArrosage']) : '0',
            'tempsArrosage' => isset($body['tempsArrosage']) ? trim((string) $body['tempsArrosage']) : '0',
            'WakeUp' => isset($body['WakeUp']) ? trim((string) $body['WakeUp']) : '0',
            'FreqWakeUp' => isset($body['FreqWakeUp']) ? trim((string) $body['FreqWakeUp']) : '0',
        ];

        try {
            $this->outputRepo->batchUpdateParameters($board, $params);
            $this->logger->info('N3ppOutputController: parametres mis a jour (output_create)', ['board' => $board]);
            return ResponseHelper::json($response, ['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('N3ppOutputController: erreur batchUpdateParameters', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }
}
