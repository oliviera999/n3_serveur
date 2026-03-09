<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\MspOutputRepository;
use App\Repository\MspSensorRepository;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MspOutputController
{
    private const BOARD = 2;

    public function __construct(
        private LogService $logger,
        private MspOutputRepository $outputRepo,
        private MspSensorRepository $sensorRepo,
        private TemplateRenderer $renderer,
    ) {
    }

    public function getState(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $action = $queryParams['action'] ?? '';
        $board = (int) ($queryParams['board'] ?? self::BOARD);

        if ($action !== 'outputs_state') {
            return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
        }

        try {
            $state = $this->outputRepo->getStateForFirmware($board);
            return ResponseHelper::json($response, $state);
        } catch (\Throwable $e) {
            $this->logger->error('MspOutputController: erreur lecture outputs', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    public function showControlPage(Request $request, Response $response): Response
    {
        $board = (int) ($request->getQueryParams()['board'] ?? self::BOARD);
        $outputs = $this->outputRepo->getAllForBoard($board);
        $lastBoardRequest = $this->outputRepo->getLastBoardRequest($board);
        $firmwareVersion = $this->sensorRepo->getFirmwareVersion();

        $html = $this->renderer->render('msp1_control.twig', [
            'page_title' => 'Contrôle station météo - Le potager',
            'outputs' => $outputs,
            'board' => $board,
            'last_board_request' => $lastBoardRequest,
            'version' => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment' => TableConfig::getEnvironment(),
            'nav_active' => 'potager_control',
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function setOutput(Request $request, Response $response): Response
    {
        $params = $request->getMethod() === 'POST' ? $request->getParsedBody() ?? [] : $request->getQueryParams();
        $action = $params['action'] ?? '';
        if ($action !== 'set') {
            return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
        }

        $name = trim((string) ($params['name'] ?? ''));
        $state = trim((string) ($params['state'] ?? '0'));
        $board = (int) ($params['board'] ?? self::BOARD);

        if ($name === '') {
            return ResponseHelper::json($response, ['error' => 'Paramètre name requis'], 400);
        }

        $state = in_array($state, ['0', '1', '1.00'], true) ? '1' : '0';

        try {
            $this->outputRepo->updateByName($name, $state, $board);
            $this->logger->info('MspOutputController: output mis a jour', ['name' => $name, 'state' => $state, 'board' => $board]);
            return ResponseHelper::json($response, ['success' => true, 'name' => $name, 'state' => $state]);
        } catch (\Throwable $e) {
            $this->logger->error('MspOutputController: erreur mise a jour', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /**
     * POST /msp1/api/outputs/toggle — API REST pour bascule d'une sortie (alignée sur FFP3).
     * Body JSON ou form : name (string), state (0|1). Board optionnel (défaut 2).
     */
    public function toggleOutput(Request $request, Response $response): Response
    {
        $params = $request->getMethod() === 'POST' ? $request->getParsedBody() ?? [] : $request->getQueryParams();
        if (is_object($params)) {
            $params = (array) $params;
        }
        $name = trim((string) ($params['name'] ?? ''));
        $state = (int) ($params['state'] ?? -1);
        $board = (int) ($params['board'] ?? self::BOARD);

        if ($name === '') {
            return ResponseHelper::json($response, ['error' => 'Paramètre name requis'], 400);
        }
        if ($state !== 0 && $state !== 1) {
            return ResponseHelper::json($response, ['error' => 'Paramètre state doit être 0 ou 1'], 400);
        }

        $stateStr = $state === 1 ? '1' : '0';
        try {
            $this->outputRepo->updateByName($name, $stateStr, $board);
            $this->logger->info('MspOutputController: toggle output', ['name' => $name, 'state' => $stateStr, 'board' => $board]);
            return ResponseHelper::json($response, ['success' => true, 'name' => $name, 'state' => $state]);
        } catch (\Throwable $e) {
            $this->logger->error('MspOutputController: erreur toggle', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }
}
