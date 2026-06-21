<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Env;
use App\Domain\User;
use App\Repository\UserRepository;
use PDOException;

/**
 * Service d'authentification pour les pages d'administration.
 *
 * Authentification par session (BDD n3_users + fallback .env temporaire) et par token.
 */
class AuthService
{
    private const SESSION_KEY = 'authenticated';
    private const SESSION_USER_KEY = 'auth_user';
    private const SESSION_USER_ID_KEY = 'auth_user_id';
    private const SESSION_ROLE_KEY = 'auth_role';
    private const COOKIE_TOKEN_NAME = 'admin_token';
    private const SESSION_TIMEOUT = 7200; // 2 heures

    /** @var array<string, int> */
    private const ROLE_LEVELS = [
        User::ROLE_READER => 1,
        User::ROLE_OPERATOR => 2,
        User::ROLE_ADMIN => 3,
    ];

    public function __construct(
        private ?UserRepository $userRepository = null,
    ) {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');
            $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
                || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
            if ($isHttps) {
                ini_set('session.cookie_secure', '1');
            }
            ini_set('session.gc_maxlifetime', (string) self::SESSION_TIMEOUT);
            session_start();
        }
    }

    /**
     * @return array{username: string, role: string, user_id: ?int, from_env: bool}|null
     */
    public function resolveCredentials(string $username, string $password): ?array
    {
        if ($this->userRepository !== null) {
            try {
                if ($this->userRepository->tableExists() && !$this->userRepository->isEmpty()) {
                    return $this->authenticateFromDatabase($username, $password);
                }
            } catch (PDOException) {
                return $this->authenticateFromEnv($username, $password);
            }
        }

        return $this->authenticateFromEnv($username, $password);
    }

    public function authenticate(string $username, string $password): bool
    {
        return $this->resolveCredentials($username, $password) !== null;
    }

    /**
     * @param array{username: string, role: string, user_id: ?int, from_env: bool} $credentials
     */
    public function login(array $credentials): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = true;
        $_SESSION[self::SESSION_USER_KEY] = $credentials['username'];
        $_SESSION[self::SESSION_USER_ID_KEY] = $credentials['user_id'];
        $_SESSION[self::SESSION_ROLE_KEY] = $credentials['role'];
        $_SESSION['auth_time'] = time();

        if ($credentials['user_id'] !== null && $this->userRepository !== null) {
            try {
                $this->userRepository->updateLastLogin($credentials['user_id']);
            } catch (PDOException) {
                // Ignorer si BDD indisponible
            }
        }
    }

    /**
     * @deprecated Utiliser login(array $credentials)
     */
    public function loginLegacy(string $username): void
    {
        $this->login([
            'username' => $username,
            'role' => User::ROLE_ADMIN,
            'user_id' => null,
            'from_env' => true,
        ]);
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            session_destroy();
        }

        if (isset($_COOKIE[self::COOKIE_TOKEN_NAME])) {
            setcookie(self::COOKIE_TOKEN_NAME, '', time() - 3600, '/');
        }
    }

    public function isAuthenticated(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        if (!isset($_SESSION[self::SESSION_KEY]) || $_SESSION[self::SESSION_KEY] !== true) {
            return false;
        }

        if (isset($_SESSION['auth_time'])) {
            $elapsed = time() - $_SESSION['auth_time'];
            if ($elapsed > self::SESSION_TIMEOUT) {
                $this->logout();
                return false;
            }
            $_SESSION['auth_time'] = time();
        }

        if (!$this->refreshDatabaseSession()) {
            $this->logout();
            return false;
        }

        return true;
    }

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

        return hash_equals($expectedToken, $token);
    }

    public function isAuthenticatedByToken(array $queryParams = []): bool
    {
        if (isset($_COOKIE[self::COOKIE_TOKEN_NAME])) {
            if ($this->validateToken($_COOKIE[self::COOKIE_TOKEN_NAME])) {
                return true;
            }
        }

        if (isset($queryParams['token'])) {
            $token = is_scalar($queryParams['token']) ? (string) $queryParams['token'] : '';
            if ($token !== '' && $this->validateToken($token)) {
                return true;
            }
        }

        return false;
    }

    public function setAdminTokenCookie(string $token): void
    {
        if (!$this->validateToken($token)) {
            return;
        }
        $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
        setcookie(self::COOKIE_TOKEN_NAME, $token, [
            'expires' => time() + (86400 * 30),
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function getCurrentUser(): ?string
    {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[self::SESSION_USER_KEY])) {
            return (string) $_SESSION[self::SESSION_USER_KEY];
        }
        return null;
    }

    public function getCurrentUserId(): ?int
    {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[self::SESSION_USER_ID_KEY])) {
            $id = $_SESSION[self::SESSION_USER_ID_KEY];
            return $id !== null ? (int) $id : null;
        }
        return null;
    }

    public function getCurrentRole(): ?string
    {
        if ($this->isAuthenticated()) {
            return (string) ($_SESSION[self::SESSION_ROLE_KEY] ?? User::ROLE_ADMIN);
        }

        if ($this->isAuthenticatedByToken($_GET ?? [])) {
            return User::ROLE_ADMIN;
        }

        return null;
    }

    public function hasMinimumRole(string $requiredRole): bool
    {
        $currentRole = $this->getCurrentRole();
        if ($currentRole === null) {
            return false;
        }

        $currentLevel = self::ROLE_LEVELS[$currentRole] ?? 0;
        $requiredLevel = self::ROLE_LEVELS[$requiredRole] ?? 99;

        return $currentLevel >= $requiredLevel;
    }

    public function isAdmin(): bool
    {
        return $this->hasMinimumRole(User::ROLE_ADMIN);
    }

    public function canAccessControl(): bool
    {
        return $this->hasMinimumRole(User::ROLE_OPERATOR);
    }

    public function canManageUsers(): bool
    {
        return $this->isAdmin();
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * @return array{username: string, role: string, user_id: ?int, from_env: bool}|null
     */
    private function authenticateFromDatabase(string $username, string $password): ?array
    {
        if ($this->userRepository === null) {
            return null;
        }

        $user = $this->userRepository->verifyPassword($username, $password);
        if ($user === null) {
            return null;
        }

        return [
            'username' => $user->username,
            'role' => $user->role,
            'user_id' => $user->id,
            'from_env' => false,
        ];
    }

    /**
     * @return array{username: string, role: string, user_id: ?int, from_env: bool}|null
     */
    private function authenticateFromEnv(string $username, string $password): ?array
    {
        Env::load();

        $expectedUsername = $_ENV['ADMIN_USERNAME'] ?? null;
        $passwordHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? null;

        if ($expectedUsername === null || $passwordHash === null) {
            return null;
        }

        if ($username !== $expectedUsername) {
            return null;
        }

        if (!password_verify($password, $passwordHash)) {
            return null;
        }

        return [
            'username' => $username,
            'role' => User::ROLE_ADMIN,
            'user_id' => null,
            'from_env' => true,
        ];
    }

    private function refreshDatabaseSession(): bool
    {
        $userId = $_SESSION[self::SESSION_USER_ID_KEY] ?? null;
        if ($userId === null) {
            return true;
        }

        if ($this->userRepository === null) {
            return true;
        }

        try {
            $user = $this->userRepository->findById((int) $userId);
        } catch (PDOException) {
            return false;
        }

        if ($user === null || !$user->isActive) {
            return false;
        }

        $_SESSION[self::SESSION_USER_KEY] = $user->username;
        $_SESSION[self::SESSION_ROLE_KEY] = $user->role;

        return true;
    }
}
