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
        return [
            'page_title' => 'Contrôle station météo - Le potager',
            'outputs' => $this->outputRepo->getAllForBoard($board),
            'board' => $board,
            'last_board_request' => $this->outputRepo->getLastBoardRequest($board),
            'version' => Version::getWithPrefix(),
            'firmware_version' => $this->sensorRepo->getFirmwareVersion(),
            'environment' => TableConfig::getEnvironment(),
            'nav_active' => 'potager_control',
        ];
    }

    protected function getStateData(int $board): array
    {
        return $this->outputRepo->getStateForFirmware($board);
    }

    protected function doToggle(array $params, int $board): array
    {
        $name = trim((string) ($params['name'] ?? ''));
        $state = (int) ($params['state'] ?? -1);

        if ($name === '') {
            return ['success' => false, 'error' => 'Paramètre name requis', 'status' => 400];
        }
        if ($state !== 0 && $state !== 1) {
            return ['success' => false, 'error' => 'Paramètre state doit être 0 ou 1', 'status' => 400];
        }

        $stateStr = $state === 1 ? '1' : '0';
        $this->outputRepo->updateByName($name, $stateStr, $board);
        return ['success' => true, 'name' => $name, 'state' => $state];
    }

    /**
     * POST legacy /msp1/msp1control/msp1-outputs-action.php
     */
    public function setOutput(Request $request, Response $response): Response
    {
        $params = $request->getMethod() === 'POST' ? $request->getParsedBody() ?? [] : $request->getQueryParams();
        $action = $params['action'] ?? '';
        if ($action !== 'set') {
            return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
        }

        $name = trim((string) ($params['name'] ?? ''));
        $state = trim((string) ($params['state'] ?? '0'));
        $board = (int) ($params['board'] ?? $this->defaultBoard());

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
}
