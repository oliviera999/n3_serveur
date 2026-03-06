<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Repository\N3ppOutputRepository;
use App\Service\LogService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Retourne l'etat des outputs pour le firmware n3pp4_2.
 * Compatibilite totale avec le contrat d'interface existant :
 *   GET /n3pp/n3ppcontrol/n3pp-outputs-action.php?action=outputs_state&board=3
 *
 * Le firmware attend un JSON avec des cles numeriques (GPIO).
 */
class N3ppOutputController
{
    public function __construct(
        private LogService $logger,
        private N3ppOutputRepository $outputRepo,
    ) {
    }

    public function getState(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $action = $queryParams['action'] ?? '';
        $board = (int) ($queryParams['board'] ?? 3);

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
}
