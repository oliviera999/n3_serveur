<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Env;

/**
 * Service d'authentification pour les pages d'administration.
 * 
 * Gère l'authentification par session et par token.
 * Les identifiants sont stockés dans les variables d'environnement.
 */
class AuthService
{
    private const SESSION_KEY = 'authenticated';
    private const SESSION_USER_KEY = 'auth_user';
    private const COOKIE_TOKEN_NAME = 'admin_token';
    private const SESSION_TIMEOUT = 7200; // 2 heures

    public function __construct()
    {
        // S'assurer que la session est démarrée
        if (session_status() === PHP_SESSION_NONE) {
            // Configuration sécurisée de la session
            ini_set('session.cookie_httponly', '1');
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
    }

    /**
     * Authentifie un utilisateur avec un nom d'utilisateur et un mot de passe.
     * 
     * @param string $username Nom d'utilisateur
     * @param string $password Mot de passe en clair
     * @return bool True si l'authentification réussit
     */
    public function authenticate(string $username, string $password): bool
    {
        Env::load();
        
        $expectedUsername = $_ENV['ADMIN_USERNAME'] ?? null;
        $passwordHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? null;
        
        if ($expectedUsername === null || $passwordHash === null) {
            return false;
        }
        
        // Vérifier le nom d'utilisateur
        if ($username !== $expectedUsername) {
            return false;
        }
        
        // Vérifier le mot de passe avec password_verify
        if (!password_verify($password, $passwordHash)) {
            return false;
        }
        
        return true;
    }

    /**
     * Connecte un utilisateur (démarre une session).
     * 
     * @param string $username Nom d'utilisateur
     * @return void
     */
    public function login(string $username): void
    {
        // Régénérer l'ID de session pour éviter la fixation de session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        
        $_SESSION[self::SESSION_KEY] = true;
        $_SESSION[self::SESSION_USER_KEY] = $username;
        $_SESSION['auth_time'] = time();
    }

    /**
     * Déconnecte l'utilisateur (détruit la session).
     * 
     * @return void
     */
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Détruire toutes les variables de session
            $_SESSION = [];
            
            // Supprimer le cookie de session
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            
            // Détruire la session
            session_destroy();
        }
        
        // Supprimer aussi le cookie de token si présent
        if (isset($_COOKIE[self::COOKIE_TOKEN_NAME])) {
            setcookie(self::COOKIE_TOKEN_NAME, '', time() - 3600, '/');
        }
    }

    /**
     * Vérifie si l'utilisateur est authentifié via session.
     * 
     * @return bool True si authentifié
     */
    public function isAuthenticated(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        
        if (!isset($_SESSION[self::SESSION_KEY]) || $_SESSION[self::SESSION_KEY] !== true) {
            return false;
        }
        
        // Vérifier le timeout de session
        if (isset($_SESSION['auth_time'])) {
            $elapsed = time() - $_SESSION['auth_time'];
            if ($elapsed > self::SESSION_TIMEOUT) {
                $this->logout();
                return false;
            }
            // Mettre à jour le temps d'authentification pour prolonger la session
            $_SESSION['auth_time'] = time();
        }
        
        return true;
    }

    /**
     * Vérifie si un token d'authentification est valide.
     * 
     * @param string|null $token Token à vérifier (peut être null)
     * @return bool True si le token est valide
     */
    public function validateToken(?string $token): bool
    {
        if ($token === null) {
            return false;
        }
        
        Env::load();
        $expectedToken = $_ENV['ADMIN_TOKEN'] ?? null;
        
        if ($expectedToken === null || $expectedToken === '') {
            return false;
        }
        
        // Comparaison timing-safe
        return hash_equals($expectedToken, $token);
    }

    /**
     * Vérifie l'authentification par token (cookie ou paramètre URL).
     * 
     * @param array $queryParams Paramètres de requête (pour token dans URL)
     * @return bool True si authentifié par token
     */
    public function isAuthenticatedByToken(array $queryParams = []): bool
    {
        // Vérifier le token dans le cookie
        if (isset($_COOKIE[self::COOKIE_TOKEN_NAME])) {
            if ($this->validateToken($_COOKIE[self::COOKIE_TOKEN_NAME])) {
                return true;
            }
        }
        
        // Vérifier le token dans les paramètres URL
        if (isset($queryParams['token'])) {
            $token = $queryParams['token'];
            if ($this->validateToken($token)) {
                // Définir le cookie pour les prochaines requêtes
                setcookie(self::COOKIE_TOKEN_NAME, $token, time() + (86400 * 30), '/'); // 30 jours
                return true;
            }
        }
        
        return false;
    }

    /**
     * Retourne le nom d'utilisateur actuellement connecté.
     * 
     * @return string|null Nom d'utilisateur ou null si non connecté
     */
    public function getCurrentUser(): ?string
    {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[self::SESSION_USER_KEY])) {
            return $_SESSION[self::SESSION_USER_KEY];
        }
        return null;
    }

    /**
     * Génère un hash de mot de passe pour stockage dans .env.
     * 
     * @param string $password Mot de passe en clair
     * @return string Hash du mot de passe
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
