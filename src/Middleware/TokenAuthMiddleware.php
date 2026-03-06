<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Security\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Middleware d'authentification par token.
 * 
 * Vérifie si l'utilisateur est authentifié via token (cookie ou paramètre URL).
 * Redirige vers /login si non authentifié.
 */
class TokenAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthService $authService
    ) {
    }

    /**
     * Récupère le basePath depuis la requête.
     */
    private function getBasePath(Request $request): string
    {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        
        if (strpos($scriptName, '/public/index.php') !== false) {
            // Accès via public/index.php : remonter de 2 niveaux depuis /public/
            $basePath = dirname(dirname($scriptName));
        } else {
            // Accès via index.php racine : utiliser le répertoire de SCRIPT_NAME
            $basePath = dirname($scriptName);
        }
        
        return rtrim($basePath, '/');
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Récupérer les paramètres de requête
        $queryParams = $request->getQueryParams();
        
        // Vérifier si l'utilisateur est authentifié par token
        if (!$this->authService->isAuthenticatedByToken($queryParams)) {
            // Récupérer le basePath
            $basePath = $this->getBasePath($request);
            
            // Récupérer l'URI demandée pour redirection après login
            $uri = $request->getUri();
            $path = $uri->getPath();
            $query = $uri->getQuery();
            
            // Construire l'URL de redirection avec le basePath
            $loginPath = ($basePath !== '' ? $basePath : '') . '/login';
            $redirectUrl = $loginPath;
            
            // Éviter la redirection infinie si on est déjà sur /login
            $loginFullPath = ($basePath !== '' ? $basePath : '') . '/login';
            $basePathSlash = ($basePath !== '' ? $basePath : '') . '/';
            if ($path !== $loginFullPath && $path !== $basePathSlash && $path !== $basePath) {
                $redirectUrl .= '?redirect=' . urlencode($path . ($query ? '?' . $query : ''));
            }
            
            // Rediriger vers la page de login
            $response = new \Slim\Psr7\Response();
            return $response
                ->withStatus(302)
                ->withHeader('Location', $redirectUrl);
        }
        
        // Utilisateur authentifié, continuer le traitement
        return $handler->handle($request);
    }
}
