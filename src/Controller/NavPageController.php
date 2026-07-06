<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\NavPageRepository;
use App\Security\AuthService;
use App\Service\LogService;
use App\Util\RequestHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Endpoint de gestion des pages affichées dans la barre de navigation.
 *
 * Les switchs de la page de supervision pilotent un état SERVEUR GLOBAL
 * (table `navPages`) : le menu résultant s'applique à tous les visiteurs, y
 * compris non connectés, et de façon permanente.
 *
 * Écriture réservée aux administrateurs (canAccessControl) et protégée par CSRF
 * (cf. CsrfMiddleware). La lecture se fait côté serveur via NavPageRepository,
 * injecté dans le rendu de chaque page (TemplateRenderer).
 */
class NavPageController
{
    public function __construct(
        private NavPageRepository $navPageRepository,
        private AuthService $authService,
        private LogService $logger,
    ) {
    }

    /**
     * Active/désactive une page dans le menu. Corps JSON attendu :
     * { key, label, url, active }.
     */
    public function toggle(Request $request, Response $response): Response
    {
        if (!$this->authService->canAccessControl()) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Action réservée aux administrateurs.',
            ], 403);
        }

        $params = RequestHelper::extractParams($request);
        $key = trim(RequestHelper::getString($params, 'key'));
        $label = trim(RequestHelper::getString($params, 'label'));
        $url = trim(RequestHelper::getString($params, 'url'));
        $active = $this->toBool($params['active'] ?? false);

        if ($key === '') {
            return $this->json($response, [
                'success' => false,
                'error' => 'Clé de page manquante.',
            ], 400);
        }

        // À l'activation, label et url sont requis (ils alimentent le lien du menu).
        if ($active && ($label === '' || $url === '')) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Label et URL requis pour activer une page.',
            ], 400);
        }

        try {
            $this->navPageRepository->setActive($key, $label, $url, $active, $this->authService->getCurrentUser());
        } catch (\Throwable $e) {
            $this->logger->error('Échec bascule page de navigation', [
                'key' => $key,
                'active' => $active,
                'error' => $e->getMessage(),
            ]);

            return $this->json($response, [
                'success' => false,
                'error' => 'Enregistrement impossible.',
            ], 500);
        }

        // Liste renvoyée pour la mise à jour live du menu. Le lien « Utilisateurs »
        // (admin-users) reste filtré par la permission, comme dans TemplateRenderer.
        $active = $this->navPageRepository->getActivePages();
        if (!$this->authService->canManageUsers()) {
            $active = array_values(array_filter(
                $active,
                static fn (array $page): bool => $page['key'] !== 'admin-users'
            ));
        }

        return $this->json($response, [
            'success' => true,
            'active' => $active,
        ]);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
