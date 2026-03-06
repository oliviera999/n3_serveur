<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Repository\MspOutputRepository;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controle et etat des outputs pour la station meteo (msp2_5).
 * GET /msp1/msp1control/msp1-outputs-action.php?action=outputs_state&board=2 (API firmware)
 * GET /msp1/msp1control/ ou index.php (page de controle)
 * GET/POST /msp1/msp1control/msp1-outputs-action.php?action=set&name=...&state=...&board=2 (mise a jour)
 */
class MspOutputController
{
    private const BOARD = 2;

    public function __construct(
        private LogService $logger,
        private MspOutputRepository $outputRepo,
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

    /**
     * Page de controle (interface web).
     */
    public function showControlPage(Request $request, Response $response): Response
    {
        $board = (int) ($request->getQueryParams()['board'] ?? self::BOARD);
        $outputs = $this->outputRepo->getAllForBoard($board);

        $html = $this->renderer->render('msp1_control.twig', [
            'page_title' => 'Contrôle station météo - Le potager',
            'outputs' => $outputs,
            'board' => $board,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Mise a jour d'un output (action=set&name=...&state=...&board=2).
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
}
