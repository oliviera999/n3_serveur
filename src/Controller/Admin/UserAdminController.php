<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\User;
use App\Security\AuthService;
use App\Security\CsrfService;
use App\Service\TemplateRenderer;
use App\Service\UserService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Gestion des utilisateurs (admin uniquement).
 */
class UserAdminController
{
    public function __construct(
        private UserService $userService,
        private TemplateRenderer $renderer,
        private AuthService $authService,
        private CsrfService $csrfService,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if ($deny = $this->requireAdmin($response)) {
            return $deny;
        }

        // Le compte admin historique (.env) doit apparaître dans le gestionnaire.
        $this->userService->ensureLegacyAdminMaterialized();

        $html = $this->renderer->render('admin/users/index.twig', [
            'page_title' => 'Gestion des utilisateurs - n3 iot datas',
            'nav_active' => 'users',
            'users' => $this->userService->listUsers(),
            'role_labels' => $this->roleLabels(),
            'flash_success' => $this->flashGet('success'),
            'flash_error' => $this->flashGet('error'),
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function showCreateForm(Request $request, Response $response): Response
    {
        if ($deny = $this->requireAdmin($response)) {
            return $deny;
        }

        return $this->renderForm($response, null, [], []);
    }

    public function create(Request $request, Response $response): Response
    {
        if ($deny = $this->requireAdmin($response)) {
            return $deny;
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !$this->csrfService->validateToken((string) ($body['_csrf_token'] ?? ''))) {
            $this->flashSet('error', 'Erreur de sécurité CSRF. Veuillez réessayer.');
            return $this->redirect($response, '/admin/users/new');
        }

        $result = $this->userService->createUser($body);
        if (isset($result['errors'])) {
            return $this->renderForm($response, null, $body, $result['errors']);
        }

        $this->flashSet('success', 'Utilisateur « ' . $result['user']->username . ' » créé avec succès.');
        return $this->redirect($response, '/admin/users');
    }

    public function showEditForm(Request $request, Response $response, array $args): Response
    {
        if ($deny = $this->requireAdmin($response)) {
            return $deny;
        }

        $id = (int) ($args['id'] ?? 0);
        $user = $this->userService->getUser($id);
        if ($user === null) {
            return $response->withStatus(404);
        }

        return $this->renderForm($response, $user, [], []);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if ($deny = $this->requireAdmin($response)) {
            return $deny;
        }

        $id = (int) ($args['id'] ?? 0);
        $user = $this->userService->getUser($id);
        if ($user === null) {
            return $response->withStatus(404);
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !$this->csrfService->validateToken((string) ($body['_csrf_token'] ?? ''))) {
            $this->flashSet('error', 'Erreur de sécurité CSRF. Veuillez réessayer.');
            return $this->redirect($response, '/admin/users/' . $id . '/edit');
        }

        $result = $this->userService->updateUser($id, $body, $this->authService->getCurrentUserId());
        if (isset($result['errors'])) {
            return $this->renderForm($response, $user, $body, $result['errors']);
        }

        $this->flashSet('success', 'Utilisateur « ' . $result['user']->username . ' » mis à jour.');
        return $this->redirect($response, '/admin/users');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($deny = $this->requireAdmin($response)) {
            return $deny;
        }

        $id = (int) ($args['id'] ?? 0);
        $body = $request->getParsedBody();
        if (!is_array($body) || !$this->csrfService->validateToken((string) ($body['_csrf_token'] ?? ''))) {
            $this->flashSet('error', 'Erreur de sécurité CSRF. Veuillez réessayer.');
            return $this->redirect($response, '/admin/users');
        }

        $result = $this->userService->deleteUser($id, $this->authService->getCurrentUserId());
        if (isset($result['errors'])) {
            $this->flashSet('error', implode(' ', $result['errors']));
        } else {
            $this->flashSet('success', 'Utilisateur supprimé.');
        }

        return $this->redirect($response, '/admin/users');
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $errors
     */
    private function renderForm(Response $response, ?User $user, array $data, array $errors): Response
    {
        $html = $this->renderer->render('admin/users/form.twig', [
            'page_title' => ($user === null ? 'Nouvel utilisateur' : 'Modifier utilisateur') . ' - n3 iot datas',
            'nav_active' => 'users',
            'user' => $user,
            'form_data' => $data,
            'errors' => $errors,
            'roles' => User::ROLES,
            'role_labels' => $this->roleLabels(),
            'is_create' => $user === null,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    private function requireAdmin(Response $response): ?Response
    {
        if (!$this->authService->canManageUsers()) {
            $html = $this->renderer->render('admin/forbidden.twig', [
                'page_title' => 'Accès refusé - n3 iot datas',
                'nav_active' => 'supervision',
                'requested_path' => '/admin/users',
                'user_role' => $this->authService->getCurrentRole(),
            ]);
            $response->getBody()->write($html);
            return $response->withStatus(403)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }
        return null;
    }

    /** @return array<string, string> */
    private function roleLabels(): array
    {
        return [
            User::ROLE_ADMIN => User::roleLabel(User::ROLE_ADMIN),
            User::ROLE_OPERATOR => User::roleLabel(User::ROLE_OPERATOR),
            User::ROLE_READER => User::roleLabel(User::ROLE_READER),
        ];
    }

    private function redirect(Response $response, string $path): Response
    {
        $basePath = $GLOBALS['base_path'] ?? '';
        $location = rtrim($basePath, '/') . $path;
        return $response->withStatus(302)->withHeader('Location', $location);
    }

    private function flashSet(string $key, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['user_admin_flash'][$key] = $message;
    }

    private function flashGet(string $key): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $message = $_SESSION['user_admin_flash'][$key] ?? null;
        unset($_SESSION['user_admin_flash'][$key]);
        return is_string($message) ? $message : null;
    }
}
