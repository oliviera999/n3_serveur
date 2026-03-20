<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Security\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Encapsule la logique d'authentification globale (session/token/both).
 */
class AuthGuardMiddleware
{
    public function __construct(
        private AuthService $authService,
        private AuthMiddleware $authMiddleware,
        private TokenAuthMiddleware $tokenAuthMiddleware,
    ) {
    }

    public function applyConfiguredAuth(Request $request, RequestHandler $handler, string $authMethod): Response
    {
        if ($authMethod === 'none' || $authMethod === '') {
            return $handler->handle($request);
        }
        if ($authMethod === 'session') {
            return $this->authMiddleware->process($request, $handler);
        }
        if ($authMethod === 'token') {
            return $this->tokenAuthMiddleware->process($request, $handler);
        }
        if ($authMethod === 'both') {
            if ($this->authService->isAuthenticated()) {
                return $handler->handle($request);
            }
            if ($this->authService->isAuthenticatedByToken($request->getQueryParams())) {
                return $handler->handle($request);
            }
            return $this->authMiddleware->process($request, $handler);
        }
        return $this->authMiddleware->process($request, $handler);
    }

    /**
     * Protège les chemins déclarés dans routes_config.php.
     *
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

        if ($isAuthenticated) {
            return $handler->handle($request);
        }

        return $this->buildLoginRedirectResponse($path);
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

