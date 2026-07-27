<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Config\Env;
use App\Domain\User;
use App\Security\AuthService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests d'AuthService isolés (sans session HTTP réelle, sans accès BDD).
 *
 * On se concentre sur :
 *   - hashPassword() / authenticate() : interaction avec password_hash/verify
 *   - validateToken() : comparaison timing-safe avec ADMIN_TOKEN
 *   - isAuthenticatedByToken() : flux cookie + queryParams
 *
 * Détail technique : `Env::load()` (appelé par AuthService) charge le `.env`
 * du repo via safeLoad de vlucas/phpdotenv. safeLoad considère getenv() pour
 * décider s'il doit override, et les écritures PHP dans `$_ENV` ne mettent pas
 * à jour getenv(). On force donc Env::$loaded=true via Reflection pour éviter
 * que les valeurs réelles du `.env` (ADMIN_USERNAME=admin, ADMIN_PASSWORD_HASH=...)
 * ne polluent les ENV de test.
 */
final class AuthServiceTest extends TestCase
{
    private string $previousUsername;
    private string $previousHash;
    private string $previousToken;
    private bool $previousLoaded;

    protected function setUp(): void
    {
        $this->previousUsername = $_ENV['ADMIN_USERNAME'] ?? '';
        $this->previousHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? '';
        $this->previousToken = $_ENV['ADMIN_TOKEN'] ?? '';

        $ref = new ReflectionClass(Env::class);
        $prop = $ref->getProperty('loaded');
        $prop->setAccessible(true);
        $this->previousLoaded = (bool) $prop->getValue();
        $prop->setValue(null, true); // bypass dotenv -> on garde nos $_ENV
    }

    protected function tearDown(): void
    {
        $_ENV['ADMIN_USERNAME'] = $this->previousUsername;
        $_ENV['ADMIN_PASSWORD_HASH'] = $this->previousHash;
        $_ENV['ADMIN_TOKEN'] = $this->previousToken;

        $ref = new ReflectionClass(Env::class);
        $prop = $ref->getProperty('loaded');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->previousLoaded);
    }

    public function testHashPasswordProducesPasswordVerifyCompatibleHash(): void
    {
        $hash = AuthService::hashPassword('motdepasse123');
        $this->assertTrue(password_verify('motdepasse123', $hash));
        $this->assertFalse(password_verify('mauvais', $hash));
    }

    public function testAuthenticateOkWithValidCredentials(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'admin-test';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('s3cret', PASSWORD_DEFAULT);
        $auth = new AuthService(null);

        $this->assertTrue($auth->authenticate('admin-test', 's3cret'));
        $this->assertFalse($auth->authenticate('admin-test', 'wrong'));
        $this->assertFalse($auth->authenticate('autre', 's3cret'));
    }

    public function testResolveCredentialsFromEnvReturnsAdminRole(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'admin-test';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('s3cret', PASSWORD_DEFAULT);
        $auth = new AuthService(null);

        $creds = $auth->resolveCredentials('admin-test', 's3cret');
        $this->assertNotNull($creds);
        $this->assertSame('admin', $creds['role']);
        $this->assertTrue($creds['from_env']);
    }

    public function testAuthenticateFailsWhenEnvIncomplete(): void
    {
        unset($_ENV['ADMIN_USERNAME'], $_ENV['ADMIN_PASSWORD_HASH']);
        $auth = new AuthService(null);

        $this->assertFalse($auth->authenticate('any', 'any'));
    }

    public function testValidateTokenTimingSafe(): void
    {
        $_ENV['ADMIN_TOKEN'] = 'super-secret-token-abc';
        $auth = new AuthService(null);

        $this->assertTrue($auth->validateToken('super-secret-token-abc'));
        $this->assertFalse($auth->validateToken('super-secret-token-xyz'));
        $this->assertFalse($auth->validateToken(null));
        $this->assertFalse($auth->validateToken(''));
    }

    public function testValidateTokenFailsWhenEnvMissing(): void
    {
        unset($_ENV['ADMIN_TOKEN']);
        $auth = new AuthService(null);
        $this->assertFalse($auth->validateToken('whatever'));
    }

    public function testIsAuthenticatedByTokenFromQueryParam(): void
    {
        $_ENV['ADMIN_TOKEN'] = 'super-secret-token-abc';
        $auth = new AuthService(null);

        $this->assertTrue($auth->isAuthenticatedByToken(['token' => 'super-secret-token-abc']));
        $this->assertFalse($auth->isAuthenticatedByToken(['token' => 'wrong']));
        $this->assertFalse($auth->isAuthenticatedByToken([]));
    }

    public function testHasValidHeaderTokenFromXAdminTokenHeader(): void
    {
        $_ENV['ADMIN_TOKEN'] = 'super-secret-token-abc';
        $auth = new AuthService(null);

        $previous = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? null;
        try {
            $_SERVER['HTTP_X_ADMIN_TOKEN'] = 'super-secret-token-abc';
            $this->assertTrue($auth->hasValidHeaderToken());
            $this->assertTrue($auth->isAuthenticatedByToken());

            $_SERVER['HTTP_X_ADMIN_TOKEN'] = 'wrong';
            $this->assertFalse($auth->hasValidHeaderToken());
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_X_ADMIN_TOKEN']);
            } else {
                $_SERVER['HTTP_X_ADMIN_TOKEN'] = $previous;
            }
        }
    }

    public function testHasValidHeaderTokenFromBearerHeader(): void
    {
        $_ENV['ADMIN_TOKEN'] = 'super-secret-token-abc';
        $auth = new AuthService(null);

        $previous = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        try {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer super-secret-token-abc';
            $this->assertTrue($auth->hasValidHeaderToken());

            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong';
            $this->assertFalse($auth->hasValidHeaderToken());
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_AUTHORIZATION']);
            } else {
                $_SERVER['HTTP_AUTHORIZATION'] = $previous;
            }
        }
    }

    /**
     * RÉGRESSION 6.32.0 : une session authentifiée SANS clé `auth_role` retombait sur
     * `ROLE_ADMIN` — un fail-open sur un contrôle d'accès (session antérieure à
     * l'introduction du champ survivant à un déploiement, store partiellement
     * désérialisé…). Le repli doit être le rôle le plus FAIBLE.
     */
    public function testMissingSessionRoleFallsBackToWeakestRole(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $previousSession = $_SESSION;

        try {
            $_SESSION = ['authenticated' => true, 'auth_time' => time()];
            unset($_SESSION['auth_role']);

            $auth = new AuthService(null);

            $this->assertSame(User::ROLE_READER, $auth->getCurrentRole());
            $this->assertFalse($auth->isAdmin(), 'une session sans rôle ne doit pas être admin');
            $this->assertFalse($auth->canAccessControl(), 'ni accéder au contrôle');
        } finally {
            $_SESSION = $previousSession;
        }
    }

    public function testExplicitSessionRoleIsPreserved(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $previousSession = $_SESSION;

        try {
            $_SESSION = [
                'authenticated' => true,
                'auth_time' => time(),
                'auth_role' => User::ROLE_OPERATOR,
            ];

            $auth = new AuthService(null);

            $this->assertSame(User::ROLE_OPERATOR, $auth->getCurrentRole());
            $this->assertTrue($auth->canAccessControl());
            $this->assertFalse($auth->isAdmin());
        } finally {
            $_SESSION = $previousSession;
        }
    }
}
