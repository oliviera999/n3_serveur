<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Config\Version;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Flux commun pour les pages de contrôle des sorties (outputs).
 *
 * Factorise : rendu du template contrôle, lecture de l'état, toggle.
 * Les sous-classes implémentent les méthodes spécifiques à leur section.
 */
abstract class AbstractOutputController
{
    public function __construct(
        protected LogService $logger,
        protected TemplateRenderer $renderer,
    ) {
    }

    /**
     * Board par défaut pour cette section.
     */
    abstract protected function defaultBoard(): int;

    /**
     * Nom court du composant (pour les logs).
     */
    abstract protected function componentName(): string;

    /**
     * Nom du template Twig pour la page de contrôle.
     */
    abstract protected function controlTemplate(): string;

    /**
     * Construit le tableau de variables à passer au template de contrôle.
     * La sous-classe fournit les données spécifiques (outputs, params, etc.).
     */
    abstract protected function buildControlPageData(int $board): array;

    /**
     * Renvoie l'état des sorties au format JSON (pour le firmware).
     */
    abstract protected function getStateData(int $board): array;

    /**
     * Met à jour une sortie. Retourne [success => bool, ...].
     */
    abstract protected function doToggle(array $params, int $board): array;

    /**
     * Affiche la page de contrôle.
     * Variables communes : version, firmware_version, environment, nav_active (fournies par buildControlPageData).
     */
    public function showControlPage(Request $request, Response $response): Response
    {
        $board = (int) ($request->getQueryParams()['board'] ?? $this->defaultBoard());

        try {
            $data = $this->buildControlPageData($board);
            $data['version'] = $data['version'] ?? Version::getWithPrefix();
            $data['environment'] = $data['environment'] ?? TableConfig::getEnvironment();

            $html = $this->renderer->render($this->controlTemplate(), $data);
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur showControlPage", ['error' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }

    /**
     * GET /section/api/outputs/state — retourne l'état au format JSON.
     */
    public function getState(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $action = $queryParams['action'] ?? 'outputs_state';
        $board = (int) ($queryParams['board'] ?? $this->defaultBoard());

        if ($action !== 'outputs_state') {
            return $this->handleLegacyAction($request, $response, $action);
        }

        try {
            $state = $this->getStateData($board);
            return ResponseHelper::json($response, $state);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur lecture outputs", ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /**
     * POST /section/api/outputs/toggle — bascule une sortie.
     */
    public function toggleOutput(Request $request, Response $response): Response
    {
        $params = $request->getMethod() === 'POST' ? $request->getParsedBody() ?? [] : $request->getQueryParams();
        if (is_object($params)) {
            $params = (array) $params;
        }
        $board = (int) ($params['board'] ?? $this->defaultBoard());

        try {
            $result = $this->doToggle($params, $board);
            if (!($result['success'] ?? false)) {
                return ResponseHelper::json($response, $result, $result['status'] ?? 400);
            }
            $this->logger->info("{$this->componentName()}: toggle output", $result);
            unset($result['status']);
            return ResponseHelper::json($response, $result);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur toggle", ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /**
     * Hook pour gérer les actions legacy via query string (output_update, output_delete, etc.).
     * Par défaut, retourne une erreur 400. Surchargé par N3ppOutputController.
     */
    protected function handleLegacyAction(Request $request, Response $response, string $action): Response
    {
        return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
    }
}
