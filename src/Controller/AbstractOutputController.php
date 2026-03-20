<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\AuthService;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\RequestHelper;
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
        protected AuthService $authService,
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
     * Exécute la mise à jour legacy action=set.
     */
    abstract protected function doSetOutput(array $params, int $board): array;

    /**
     * Liste des clés de paramètres supportées par le module.
     *
     * @return string[]
     */
    abstract protected function getDefaultParamKeys(): array;

    abstract protected function updateParameterByName(int $board, string $paramName, string $value): bool;

    /**
     * @param array<string, mixed> $params
     */
    abstract protected function batchUpdateParameters(int $board, array $params): void;

    /**
     * Normalise une valeur de state brute vers '0' ou '1'.
     */
    protected function normalizeOutputState(string $state): string
    {
        return in_array($state, ['1', '1.00'], true) ? '1' : '0';
    }

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
        $isAuth = $this->authService->isAuthenticated()
            || $this->authService->isAuthenticatedByToken($request->getQueryParams());
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

    /** @return array<string, string> */
    protected function getDefaultParams(): array
    {
        $params = [];
        foreach ($this->getDefaultParamKeys() as $key) {
            $params[$key] = $key === 'mail' ? '' : ($key === 'mailNotif' ? 'false' : '0');
        }
        return $params;
    }

    /**
     * API: Met a jour un parametre.
     */
    public function updateParameters(Request $request, Response $response): Response
    {
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }
        $payload = RequestHelper::extractParams($request);
        if (isset($payload['param'])) {
            $payload = [$payload['param'] => $payload['value'] ?? null];
        }
        if (!is_array($payload) || $payload === []) {
            return ResponseHelper::json($response, ['error' => 'Paramètre manquant'], 400);
        }

        $board = $this->defaultBoard();
        $paramName = (string) array_key_first($payload);
        $value = trim((string) ($payload[$paramName] ?? ''));

        if ($paramName === 'mailNotif') {
            $value = in_array(strtolower($value), ['1', 'true', 'checked', 'on', 'oui'], true) ? 'checked' : 'false';
        }
        if ($paramName === 'WakeUp') {
            $value = in_array($value, ['1', 'true', 'on'], true) ? '1' : '0';
        }

        try {
            $ok = $this->updateParameterByName($board, $paramName, $value);
            if (!$ok) {
                return ResponseHelper::json($response, ['error' => 'Paramètre inconnu'], 400);
            }
            $this->logger->info("{$this->componentName()}: parametre mis a jour", ['param' => $paramName]);
            return ResponseHelper::json($response, ['success' => true, 'param' => $paramName]);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur updateParameterByName", ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /**
     * POST legacy /{module}/{module}control/{module}-outputs-action.php
     */
    public function setOutput(Request $request, Response $response): Response
    {
        $authError = $this->requireAuth($request, $response);
        if ($authError !== null) {
            return $authError;
        }
        $params = $request->getMethod() === 'POST' ? $request->getParsedBody() ?? [] : $request->getQueryParams();
        $action = $params['action'] ?? '';

        if ($action === 'output_create') {
            return $this->handleOutputCreate($request, $response);
        }
        if ($action !== 'set') {
            return ResponseHelper::json($response, ['error' => 'Action inconnue'], 400);
        }

        $board = (int) ($params['board'] ?? $this->defaultBoard());
        try {
            $result = $this->doSetOutput($params, $board);
            if (($result['success'] ?? false) === true) {
                $this->logger->info("{$this->componentName()}: output mis a jour", $result + ['board' => $board]);
                return ResponseHelper::json($response, $result);
            }
            $status = (int) ($result['status'] ?? 400);
            unset($result['status']);
            return ResponseHelper::json($response, $result, $status);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur mise a jour", ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    private function handleOutputCreate(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $board = $this->defaultBoard();
        $params = $this->getDefaultParams();
        foreach ($this->getDefaultParamKeys() as $key) {
            if (isset($body[$key])) {
                $params[$key] = trim((string) $body[$key]);
            }
        }

        try {
            $this->batchUpdateParameters($board, $params);
            $this->logger->info("{$this->componentName()}: parametres mis a jour (output_create)", ['board' => $board]);
            return ResponseHelper::json($response, ['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error("{$this->componentName()}: erreur batchUpdateParameters", ['error' => $e->getMessage()]);
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }
}
