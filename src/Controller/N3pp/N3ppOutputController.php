<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\N3ppOutputRepository;
use App\Repository\N3ppSensorRepository;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class N3ppOutputController
{
    private const BOARD = 3;

    public function __construct(
        private LogService $logger,
        private N3ppOutputRepository $outputRepo,
        private N3ppSensorRepository $sensorRepo,
        private TemplateRenderer $renderer,
    ) {
    }

    public function getState(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $action = $queryParams['action'] ?? '';
        $board = (int) ($queryParams['board'] ?? self::BOARD);

        if ($action === 'output_update') {
            return $this->handleOutputUpdate($request, $response);
        }
        if ($action === 'output_delete') {
            return $this->handleOutputDelete($request, $response);
        }
        if ($action !== 'outputs_state') {
            return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
        }

        try {
            $state = $this->outputRepo->getStateForFirmware($board);
            return ResponseHelper::json($response, $state);
        } catch (\Throwable $e) {
            $this->logger->error('N3ppOutputController: erreur lecture outputs', ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    public function showControlPage(Request $request, Response $response): Response
    {
        $board = (int) ($request->getQueryParams()['board'] ?? self::BOARD);
        $part_outputs = $this->outputRepo->getPartOutputs($board, 3);
        $params = $this->outputRepo->getParametersForBoard($board);
        $lastBoardRequest = $this->outputRepo->getLastBoardRequest($board);
        $firmwareVersion = $this->sensorRepo->getFirmwareVersion();

        $html = $this->renderer->render('n3pp_control.twig', [
            'page_title' => 'Contrôle serre / élevage - n3 iot',
            'part_outputs' => $part_outputs,
            'params' => $params,
            'board' => $board,
            'last_board_request' => $lastBoardRequest,
            'version' => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment' => TableConfig::getEnvironment(),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

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
        $board = (int) ($params['board'] ?? self::BOARD);

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
        $board = self::BOARD;
        $mail = isset($body['mail']) ? trim((string) $body['mail']) : '';
        $mailNotif = isset($body['mailNotif']) ? trim((string) $body['mailNotif']) : 'false';
        $SeuilSec = isset($body['SeuilSec']) ? trim((string) $body['SeuilSec']) : '0';
        $SeuilPontDiv = isset($body['SeuilPontDiv']) ? trim((string) $body['SeuilPontDiv']) : '0';
        $HeureArrosage = isset($body['HeureArrosage']) ? trim((string) $body['HeureArrosage']) : '0';
        $tempsArrosage = isset($body['tempsArrosage']) ? trim((string) $body['tempsArrosage']) : '0';
        $WakeUp = isset($body['WakeUp']) ? trim((string) $body['WakeUp']) : '0';
        $FreqWakeUp = isset($body['FreqWakeUp']) ? trim((string) $body['FreqWakeUp']) : '0';

        $params = [
            'mail' => $mail,
            'mailNotif' => $mailNotif,
            'SeuilSec' => $SeuilSec,
            'SeuilPontDiv' => $SeuilPontDiv,
            'HeureArrosage' => $HeureArrosage,
            'tempsArrosage' => $tempsArrosage,
            'WakeUp' => $WakeUp,
            'FreqWakeUp' => $FreqWakeUp,
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
