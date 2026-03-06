<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Repository\MspOutputRepository;
use App\Service\LogService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Retourne l'etat des outputs pour le firmware msp2_5.
 * Compatibilite totale avec le contrat d'interface existant :
 *   GET /msp1/msp1control/msp1-outputs-action.php?action=outputs_state&board=2
 *
 * Le firmware attend un JSON avec des cles nommees (resetMode, mail, etc.)
 */
class MspOutputController
{
    public function __construct(
        private LogService $logger,
        private MspOutputRepository $outputRepo,
    ) {
    }

    public function getState(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $action = $queryParams['action'] ?? '';
        $board = (int) ($queryParams['board'] ?? 2);

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
}
