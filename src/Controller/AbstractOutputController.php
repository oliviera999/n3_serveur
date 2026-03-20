<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur abstrait pour les pages de contrôle GPIO/outputs (MSP1, N3PP).
 * FFP3 conserve son propre OutputController (logique OTA, cache, multi-boards).
 */
abstract class AbstractOutputController
{
    public function __construct(
        protected LogService $logger,
        protected TemplateRenderer $renderer,
    ) {}

    abstract protected function defaultBoard(): int;
    abstract protected function componentName(): string;
    abstract protected function controlTemplate(): string;

    /**
     * Données spécifiques pour le template de la page contrôle.
     */
    abstract protected function buildControlPageData(int $board): array;

    /**
     * Construit la structure control_config commune (sidebar, titres, etc.).
     */
    protected function makeControlConfig(
        string $testEnv,
        string $sidebarTitle,
        string $sidebarDescription,
        int $outputsCount,
        string $icon,
        string $mainTitle,
        string $mainDescription,
        string $defaultApiBase
    ): array {
        return [
            'test_env' => $testEnv,
            'sidebar_title' => $sidebarTitle,
            'sidebar_description' => $sidebarDescription,
            'outputs_count' => $outputsCount,
            'icon' => $icon,
            'main_title' => $mainTitle,
            'main_description' => $mainDescription,
            'default_api_base' => $defaultApiBase,
        ];
    }

    /**
     * Retourne l'état des sorties pour le firmware (format dépend du module).
     */
    abstract protected function getStateData(int $board): array;

    /**
     * Exécute le toggle d'un output. Retourne un tableau avec 'success' et éventuellement 'error'/'status'.
     */
    abstract protected function doToggle(array $params, int $board): array;

    /**
     * Traitement des actions legacy (surcharge optionnelle).
     */
    protected function handleLegacyAction(Request $request, Response $response, string $action): Response
    {
        return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
    }

    /**
     * Affiche la page de contrôle.
     */
    public function showControlPage(Request $request, Response $response): Response
    {
        try {
            $board = $this->defaultBoard();
            $data = $this->buildControlPageData($board);
            $html = $this->renderer->render($this->controlTemplate(), $data);
            $response->getBody()->write($html);
            return $response;
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur showControlPage — {msg}", ['msg' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }

    /**
     * GET legacy : retourne l'état des outputs pour le firmware.
     */
    public function getState(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $action = $queryParams['action'] ?? '';

        if ($action !== '' && $action !== 'outputs_state') {
            return $this->handleLegacyAction($request, $response, $action);
        }

        $board = (int) ($queryParams['board'] ?? $this->defaultBoard());

        try {
            $states = $this->getStateData($board);
            return ResponseHelper::json($response, $states);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur getState — {msg}", ['msg' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /**
     * Vérifie si la requête est authentifiée (session ou token).
     * Utilisé pour protéger les actions de contrôle (toggle, setOutput, parameters).
     */
    protected function requireAuth(Request $request, Response $response): ?Response
    {
        $authService = null;
        try {
            $authService = new \App\Security\AuthService();
        } catch (\Throwable) {
            return null;
        }

        $isAuth = $authService->isAuthenticated()
            || $authService->isAuthenticatedByToken($request->getQueryParams());
        if ($isAuth) {
            return null;
        }
        return ResponseHelper::json($response, ['error' => 'Authentification requise'], 401);
    }

    /**
     * POST API : bascule un output.
     */
    public function toggleOutput(Request $request, Response $response): Response
    {
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }
        $params = array_merge($request->getQueryParams(), $request->getParsedBody() ?? []);
        $board = (int) ($params['board'] ?? $this->defaultBoard());

        try {
            $result = $this->doToggle($params, $board);
            if (isset($result['success']) && $result['success'] === true) {
                $this->logger->info("{$this->componentName()}: toggle ok", $result);
                return ResponseHelper::json($response, $result);
            }
            $status = $result['status'] ?? 400;
            unset($result['status']);
            return ResponseHelper::json($response, $result, $status);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur toggle — {msg}", ['msg' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }
}
