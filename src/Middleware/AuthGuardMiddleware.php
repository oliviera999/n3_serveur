<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Security\AuthService;
use App\Security\RoleAccessService;
use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Encapsule la logique d'authentification globale (session/token/both) et contrôle des rôles.
 */
class AuthGuardMiddleware
{
    public function __construct(
        private AuthService $authService,
        private AuthMiddleware $authMiddleware,
        private TokenAuthMiddleware $tokenAuthMiddleware,
        private ?RoleAccessService $roleAccessService = null,
        private ?TemplateRenderer $templateRenderer = null,
    ) {
    }

    public function applyConfiguredAuth(Request $request, RequestHandler $handler, string $authMethod): Response
    {
        if ($authMethod === 'none' || $authMethod === '') {
            return $handler->handle($request);
        }
        if ($authMethod === 'session') {
            return $this->processWithRoleCheck($request, $handler);
        }
        if ($authMethod === 'token') {
            return $this->tokenAuthMiddleware->process($request, $handler);
        }
        if ($authMethod === 'both') {
            if ($this->authService->isAuthenticated()) {
                return $this->processWithRoleCheck($request, $handler);
            }
            if ($this->authService->isAuthenticatedByToken($request->getQueryParams())) {
                return $this->processWithRoleCheck($request, $handler);
            }
            return $this->authMiddleware->process($request, $handler);
        }
        return $this->authMiddleware->process($request, $handler);
    }

    /**
     * @param array<string, mixed> $routesConfig
     */
    public function processProtectedPaths(
        Request $request,
        RequestHandler $handler,
        string $authMethod,
        array $routesConfig
    ): Response {
        if ($authMethod === 'none' || $authMethod === '') {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if ($this->isPublicPath($path, $routesConfig)) {
            return $handler->handle($request);
        }
        if (!$this->isProtectedPath($path, $routesConfig)) {
            return $handler->handle($request);
        }

        $isAuthenticated = false;
        if ($authMethod === 'session' || $authMethod === 'both') {
            $isAuthenticated = $this->authService->isAuthenticated();
        }
        if (!$isAuthenticated && ($authMethod === 'token' || $authMethod === 'both')) {
            $isAuthenticated = $this->authService->isAuthenticatedByToken($request->getQueryParams());
        }

        if (!$isAuthenticated) {
            return $this->buildLoginRedirectResponse($path);
        }

        if (!$this->hasRoleAccess($path)) {
            return $this->buildForbiddenResponse($path);
        }

        return $handler->handle($request);
    }

    private function processWithRoleCheck(Request $request, RequestHandler $handler): Response
    {
        $path = $request->getUri()->getPath();
        if (!$this->hasRoleAccess($path)) {
            return $this->buildForbiddenResponse($path);
        }
        return $handler->handle($request);
    }

    private function hasRoleAccess(string $path): bool
    {
        if ($this->roleAccessService === null) {
            return true;
        }
        return $this->roleAccessService->hasAccess($path, $this->authService->getCurrentRole());
    }

    /**
     * @param array<string, mixed> $routesConfig
     */
    private function isPublicPath(string $path, array $routesConfig): bool
    {
        $exactPublicPaths = $routesConfig['exact_public_paths'] ?? [];
        $publicPaths = $routesConfig['public_paths'] ?? [];
        if (in_array($path, $exactPublicPaths, true)) {
            return true;
        }
        foreach ($publicPaths as $publicPath) {
            if (strpos($path, (string) $publicPath) === 0) {
                return true;
            }
        }
        return preg_match('#^/(ffp3/)?api/outputs(-test|3-test|3)?/state$#', $path) === 1;
    }

    /**
     * @param array<string, mixed> $routesConfig
     */
    private function isProtectedPath(string $path, array $routesConfig): bool
    {
        $protectedPaths = $routesConfig['protected_paths'] ?? [];
        foreach ($protectedPaths as $protectedPath) {
            if (strpos($path, (string) $protectedPath) === 0) {
                return true;
            }
        }
        return false;
    }

    private function buildLoginRedirectResponse(string $path): Response
    {
        $basePath = self::resolveBasePath();
        $loginPath = ($basePath !== '' ? $basePath : '') . '/login';
        $redirectUrl = $loginPath . '?redirect=' . urlencode($path);
        $response = new \Slim\Psr7\Response();
        return $response
            ->withStatus(302)
            ->withHeader('Location', $redirectUrl);
    }

    private function buildForbiddenResponse(string $path): Response
    {
        $response = new \Slim\Psr7\Response();
        if ($this->templateRenderer !== null) {
            $html = $this->templateRenderer->render('admin/forbidden.twig', [
                'page_title' => 'Accès refusé - n3 iot datas',
                'nav_active' => 'supervision',
                'requested_path' => $path,
                'user_role' => $this->authService->getCurrentRole(),
            ]);
            $response->getBody()->write($html);
            return $response->withStatus(403)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $response->getBody()->write('<h1>403 — Accès refusé</h1><p>Votre rôle ne permet pas d\'accéder à cette page.</p>');
        return $response->withStatus(403)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private static function resolveBasePath(): string
    {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if (strpos($scriptName, '/public/index.php') !== false) {
            $basePath = dirname(dirname($scriptName));
        } else {
            $basePath = dirname($scriptName);
        }
        return rtrim($basePath, '/');
    }
}
