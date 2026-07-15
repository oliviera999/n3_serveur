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

    /**
     * Hash bcrypt bidon (mot de passe aléatoire inconnu) servant à exécuter un
     * password_verify factice quand l'utilisateur n'existe pas / le username ne
     * correspond pas, afin d'égaliser le temps de réponse et d'éviter
     * l'énumération d'utilisateurs par analyse de timing (B2).
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$4emarLin6MEkat6qo8fXHOgMKk9yxjaa.kJcBNOD1Ti4Jbh6tSrGS';

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
            self::hardenSessionCookie();
            ini_set('session.gc_maxlifetime', (string) self::SESSION_TIMEOUT);
            session_start();
        }
    }

    /**
     * Applique le durcissement des cookies de session (httpOnly, SameSite=Lax,
     * Secure conditionnel) — À APPELER AVANT session_start().
     *
     * Statique et réutilisable par les autres services susceptibles de démarrer
     * la session en premier (ex. {@see CsrfService}), afin de ne pas perdre le
     * durcissement selon l'ordre d'initialisation (B3).
     */
    public static function hardenSessionCookie(): void
    {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        if (self::isHttpsRequest()) {
            ini_set('session.cookie_secure', '1');
        }
    }

    private static function isHttpsRequest(): bool
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    }

    /**
     * @return array{username: string, role: string, user_id: ?int, from_env: bool}|null
     */
    public function resolveCredentials(string $username, string $password): ?array
    {
        $dbResult = $this->authenticateFromDatabase($username, $password);
        if ($dbResult !== null) {
            return $dbResult;
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

    /**
     * Authentifie la requête par token d'accès admin.
     *
     * Le token est accepté via trois canaux (par ordre de préférence) :
     *  1. cookie httpOnly `admin_token` (posé après validation) ;
     *  2. en-tête sûr (`Authorization: Bearer <token>` / `X-Admin-Token`) —
     *     non-ambiant, utilisable comme exemption CSRF ;
     *  3. paramètre d'URL `?token=` (legacy) — conservé car tout le front des
     *     pages de contrôle et de la galerie propage le token via l'URL
     *     (`_control_init_js.twig::withCurrentToken`) et plusieurs middlewares
     *     en dépendent.
     *
     * ⚠️ M4 (sécurité) : le token en query string fuit dans les logs serveur,
     * l'en-tête Referer et l'historique navigateur. La cible est de migrer le
     * front vers l'en-tête `X-Admin-Token` (canaux 1/2) puis de retirer le
     * canal 3. Tant que le front n'est pas migré, on le conserve pour ne pas
     * casser l'accès au contrôle. Voir docs/AUTHENTICATION.md.
     *
     * @param array<string, mixed> $queryParams Paramètres de requête (`token`).
     */
    public function isAuthenticatedByToken(array $queryParams = []): bool
    {
        if (isset($_COOKIE[self::COOKIE_TOKEN_NAME])
            && $this->validateToken($_COOKIE[self::COOKIE_TOKEN_NAME])) {
            return true;
        }

        if (isset($queryParams['token'])) {
            $token = is_scalar($queryParams['token']) ? (string) $queryParams['token'] : '';
            if ($token !== '' && $this->validateToken($token)) {
                return true;
            }
        }

        return $this->hasValidHeaderToken();
    }

    /**
     * Vrai si la requête porte un token admin valide dans un en-tête sûr
     * (`Authorization: Bearer` ou `X-Admin-Token`).
     *
     * Contrairement au cookie (ambiant, envoyé automatiquement par le
     * navigateur), un token porté par un en-tête custom ne peut pas être ajouté
     * en cross-site : cette variante est donc utilisable comme exemption CSRF.
     */
    public function hasValidHeaderToken(): bool
    {
        $token = $this->extractHeaderToken();

        return $token !== null && $this->validateToken($token);
    }

    private function extractHeaderToken(): ?string
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION']
            ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (is_string($authorization)
            && preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m) === 1) {
            $token = trim($m[1]);
            if ($token !== '') {
                return $token;
            }
        }

        $xAdminToken = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
        if (is_string($xAdminToken) && $xAdminToken !== '') {
            return $xAdminToken;
        }

        return null;
    }

    public function setAdminTokenCookie(string $token): void
    {
        if (!$this->validateToken($token)) {
            return;
        }
        $secure = self::isHttpsRequest();
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
            return (int) $id;
        }
        return null;
    }

    public function getCurrentRole(): ?string
    {
        if ($this->isAuthenticated()) {
            return (string) ($_SESSION[self::SESSION_ROLE_KEY] ?? User::ROLE_ADMIN);
        }

        if ($this->isAuthenticatedByToken($_GET)) {
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
     * Exécute un password_verify sur un hash bidon pour consommer un temps CPU
     * comparable à une vérification réelle. Utilisé quand aucun utilisateur ne
     * correspond, afin de neutraliser l'énumération d'utilisateurs par timing (B2).
     */
    private static function dummyPasswordVerify(): void
    {
        password_verify('invalid', self::DUMMY_PASSWORD_HASH);
    }

    /**
     * @return array{username: string, role: string, user_id: ?int, from_env: bool}|null
     */
    private function authenticateFromDatabase(string $username, string $password): ?array
    {
        if ($this->userRepository === null) {
            return null;
        }

        try {
            if (!$this->userRepository->tableExists() || $this->userRepository->isEmpty()) {
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
        } catch (PDOException) {
            return null;
        }
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
            // Config incomplète : exécuter un verify factice pour ne pas répondre
            // plus vite que le cas nominal (égalisation du timing, B2).
            self::dummyPasswordVerify();
            return null;
        }

        if ($username !== $expectedUsername) {
            // Username inconnu : verify factice pour égaliser le temps de réponse
            // avec le cas « bon username / mauvais mot de passe » (anti-énumération, B2).
            self::dummyPasswordVerify();
            return null;
        }

        if (!password_verify($password, (string) $passwordHash)) {
            return null;
        }

        return [
            'username' => $username,
            'role' => User::ROLE_ADMIN,
            'user_id' => null,
            'from_env' => true,
        ];
    }
}
