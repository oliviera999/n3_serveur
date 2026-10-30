<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Config\Version;
use App\Security\AuthService;
use App\Service\DisplayPresetService;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Presets d'affichage des pages de suivi (MSP1, N3PP, ENERGIE, FFP3).
 *
 * API JSON (opérateur+, CSRF) : GET/POST /api/display-presets/{page},
 * DELETE /api/display-presets/{page}/{slug}.
 * Page dédiée ENERGIE (pas de page de contrôle GPIO) : GET /energie-control[/…].
 * FFP3 : éditeur aussi sur `/aquaponie-control`.
 */
class DisplayPresetController
{
    public function __construct(
        private DisplayPresetService $displayPresets,
        private AuthService $authService,
        private TemplateRenderer $renderer,
        private LogService $logger,
    ) {
    }

    /**
     * Catalogue + presets pour l'éditeur (ou rechargement après écriture).
     */
    public function list(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->requireOperator($response)) {
            return $denied;
        }

        $catalog = $this->catalogOrNull((string) ($args['page'] ?? ''));
        if ($catalog === null) {
            return ResponseHelper::json($response, ['success' => false, 'error' => 'Page inconnue.'], 404);
        }

        return ResponseHelper::json($response, [
            'success' => true,
            'data' => $this->displayPresets->editorData($catalog, TableConfig::getEnvironment()),
        ]);
    }

    /**
     * Crée ou met à jour un preset (corps JSON).
     */
    public function save(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->requireOperator($response)) {
            return $denied;
        }

        $catalog = $this->catalogOrNull((string) ($args['page'] ?? ''));
        if ($catalog === null) {
            return ResponseHelper::json($response, ['success' => false, 'error' => 'Page inconnue.'], 404);
        }

        $input = RequestHelper::extractParams($request);
        try {
            $this->displayPresets->save($catalog, $input, $this->authService->getCurrentUser());
        } catch (\InvalidArgumentException $e) {
            return ResponseHelper::json($response, ['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->logger->error('Échec enregistrement preset d\'affichage', [
                'page' => $catalog->pageKey(),
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::json($response, ['success' => false, 'error' => 'Enregistrement impossible.'], 500);
        }

        return ResponseHelper::json($response, [
            'success' => true,
            'data' => $this->displayPresets->editorData($catalog, TableConfig::getEnvironment()),
        ]);
    }

    /**
     * Supprime un preset enregistré (réinitialise un preset intégré).
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($denied = $this->requireOperator($response)) {
            return $denied;
        }

        $catalog = $this->catalogOrNull((string) ($args['page'] ?? ''));
        if ($catalog === null) {
            return ResponseHelper::json($response, ['success' => false, 'error' => 'Page inconnue.'], 404);
        }

        $slug = strtolower(trim((string) ($args['slug'] ?? '')));
        if (!preg_match(DisplayPresetService::SLUG_PATTERN, $slug)) {
            return ResponseHelper::json($response, ['success' => false, 'error' => 'Identifiant de preset invalide.'], 400);
        }

        try {
            $deleted = $this->displayPresets->delete($catalog, $slug);
        } catch (\Throwable $e) {
            $this->logger->error('Échec suppression preset d\'affichage', [
                'page' => $catalog->pageKey(),
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::json($response, ['success' => false, 'error' => 'Suppression impossible.'], 500);
        }

        if (!$deleted) {
            return ResponseHelper::json($response, [
                'success' => false,
                'error' => 'Aucun preset enregistré à supprimer (déjà la définition intégrée).',
            ], 404);
        }

        return ResponseHelper::json($response, [
            'success' => true,
            'data' => $this->displayPresets->editorData($catalog, TableConfig::getEnvironment()),
        ]);
    }

    /**
     * Page de contrôle ENERGIE : éditeur de presets uniquement (famille mesure seule).
     */
    public function showEnergieControl(Request $request, Response $response): Response
    {
        if (!$this->authService->isAuthenticated()
            && !$this->authService->isAuthenticatedByToken($request->getQueryParams())) {
            $redirect = '/login?redirect=' . urlencode($request->getUri()->getPath());

            return $response->withHeader('Location', $redirect)->withStatus(302);
        }
        if (!$this->authService->canAccessControl()) {
            return ResponseHelper::text($response, 'Accès réservé aux opérateurs.', 403);
        }

        $catalog = $this->displayPresets->catalog('energie');
        if ($catalog === null) {
            return ResponseHelper::text($response, 'Catalogue énergie introuvable.', 500);
        }

        $environment = TableConfig::getEnvironment();
        $html = $this->renderer->render('energie_control.twig', [
            'page_title' => 'Affichage — Banc énergie',
            'nav_active' => 'energie_control',
            'environment' => $environment,
            'version' => Version::getWithPrefix(),
            'display_presets' => $this->displayPresets->editorData($catalog, $environment),
            'display_presets_api' => '/api/display-presets/energie',
        ]);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function requireOperator(Response $response): ?Response
    {
        if (!$this->authService->canAccessControl()) {
            return ResponseHelper::json($response, [
                'success' => false,
                'error' => 'Action réservée aux opérateurs.',
            ], 403);
        }

        return null;
    }

    private function catalogOrNull(string $pageKey): ?\App\Display\PageCatalog
    {
        if ($pageKey === '' || !preg_match('/^[a-z0-9_-]{1,32}$/', $pageKey)) {
            return null;
        }

        return $this->displayPresets->catalog($pageKey);
    }
}
