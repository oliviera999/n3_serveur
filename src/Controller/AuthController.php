<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\AuthService;
use App\Security\CsrfService;
use App\Service\TemplateRenderer;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur d'authentification.
 * 
 * Gère l'affichage du formulaire de login et le traitement de l'authentification.
 */
class AuthController
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOGIN_ATTEMPT_WINDOW = 900; // 15 minutes

    public function __construct(
        private AuthService $authService,
        private TemplateRenderer $renderer,
        private CsrfService $csrfService
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

    /**
     * Affiche le formulaire de login.
     */
    public function showLogin(Request $request, Response $response): Response
    {
        // Récupérer le basePath
        $basePath = $this->getBasePath($request);
        
        // Si déjà authentifié, rediriger vers la page demandée ou l'accueil
        if ($this->authService->isAuthenticated()) {
            $queryParams = $request->getQueryParams();
            $redirectUrl = $queryParams['redirect'] ?? ($basePath !== '' ? $basePath : '') . '/';
            return $response
                ->withStatus(302)
                ->withHeader('Location', $redirectUrl);
        }

        $queryParams = $request->getQueryParams();
        $error = $queryParams['error'] ?? null;
        $redirect = $queryParams['redirect'] ?? ($basePath !== '' ? $basePath : '') . '/';

        $html = $this->renderer->render('login.twig', [
            'page_title' => 'Connexion - Administration',
            'error' => $error,
            'redirect' => $redirect,
            'base_path' => $basePath !== '' ? $basePath : '',
            'csrf_token' => $this->csrfService->getToken()
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Traite le formulaire de login.
     */
    public function handleLogin(Request $request, Response $response): Response
    {
        // Récupérer le basePath
        $basePath = $this->getBasePath($request);
        
        // Vérifier le rate limiting
        if ($this->isRateLimited()) {
            return $this->redirectToLogin($response, $basePath, 'Trop de tentatives de connexion. Veuillez réessayer dans quelques minutes.');
        }

        $body = $request->getParsedBody();
        $username = $body['username'] ?? '';
        $password = $body['password'] ?? '';
        $redirect = $body['redirect'] ?? ($basePath !== '' ? $basePath : '') . '/';
        $csrfToken = $body['_csrf_token'] ?? '';

        // Vérifier le token CSRF
        if (!$this->csrfService->validateToken($csrfToken)) {
            $this->recordLoginAttempt(false);
            return $this->redirectToLogin($response, $basePath, 'Erreur de sécurité. Veuillez réessayer.', $redirect);
        }

        // Vérifier les identifiants
        if (empty($username) || empty($password)) {
            $this->recordLoginAttempt(false);
            return $this->redirectToLogin($response, $basePath, 'Veuillez saisir un nom d\'utilisateur et un mot de passe.', $redirect);
        }

        // Authentifier l'utilisateur
        if ($this->authService->authenticate($username, $password)) {
            $this->authService->login($username);
            $this->recordLoginAttempt(true);
            
            // Rediriger vers la page demandée ou l'accueil
            return $response
                ->withStatus(302)
                ->withHeader('Location', $redirect);
        }

        // Échec de l'authentification
        $this->recordLoginAttempt(false);
        return $this->redirectToLogin($response, $basePath, 'Nom d\'utilisateur ou mot de passe incorrect.', $redirect);
    }

    /**
     * Déconnecte l'utilisateur.
     */
    public function handleLogout(Request $request, Response $response): Response
    {
        $this->authService->logout();
        
        // Récupérer le basePath
        $basePath = $this->getBasePath($request);
        $loginUrl = ($basePath !== '' ? $basePath : '') . '/login?message=' . urlencode('Vous avez été déconnecté.');
        
        return $response
            ->withStatus(302)
            ->withHeader('Location', $loginUrl);
    }

    /**
     * Redirige vers la page de login avec un message d'erreur.
     */
    private function redirectToLogin(Response $response, string $basePath, string $error, string $redirect = '/'): Response
    {
        $loginPath = rtrim($basePath, '/') . '/login';
        $redirectUrl = $loginPath . '?error=' . urlencode($error) . '&redirect=' . urlencode($redirect);
        return $response
            ->withStatus(302)
            ->withHeader('Location', $redirectUrl);
    }

    /**
     * Vérifie si le rate limiting est actif (trop de tentatives).
     */
    private function isRateLimited(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $attempts = $_SESSION['login_attempts'] ?? [];
        $now = time();
        
        // Nettoyer les tentatives anciennes
        $attempts = array_filter($attempts, function($timestamp) use ($now) {
            return ($now - $timestamp) < self::LOGIN_ATTEMPT_WINDOW;
        });
        
        // Vérifier le nombre de tentatives
        if (count($attempts) >= self::MAX_LOGIN_ATTEMPTS) {
            return true;
        }
        
        return false;
    }

    /**
     * Enregistre une tentative de login.
     */
    private function recordLoginAttempt(bool $success): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if ($success) {
            // Réussite : réinitialiser les tentatives
            unset($_SESSION['login_attempts']);
        } else {
            // Échec : enregistrer la tentative
            if (!isset($_SESSION['login_attempts'])) {
                $_SESSION['login_attempts'] = [];
            }
            $_SESSION['login_attempts'][] = time();
        }
    }
}
