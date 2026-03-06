<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Repository\N3ppOutputRepository;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controle et etat des outputs pour la serre (n3pp4_2).
 * GET /n3pp/n3ppcontrol/n3pp-outputs-action.php?action=outputs_state&board=3 (API firmware)
 * GET /n3pp/n3ppcontrol/ ou index.php (page de controle)
 * GET/POST /n3pp/n3ppcontrol/n3pp-outputs-action.php?action=set&gpio=...&state=...&board=3 (mise a jour)
 */
class N3ppOutputController
{
    private const BOARD = 3;

    public function __construct(
        private LogService $logger,
        private N3ppOutputRepository $outputRepo,
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
            $this->logger->error('N3ppOutputController: erreur lecture outputs', ['error' => $e->getMessage()]);
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

        $html = $this->renderer->render('n3pp_control.twig', [
            'page_title' => 'Contrôle serre / élevage - n3 iot',
            'outputs' => $outputs,
            'board' => $board,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Mise a jour d'un output (action=set&gpio=...&state=...&board=3).
     */
    public function setOutput(Request $request, Response $response): Response
    {
        $params = $request->getMethod() === 'POST' ? $request->getParsedBody() ?? [] : $request->getQueryParams();
        $action = $params['action'] ?? '';
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
}
