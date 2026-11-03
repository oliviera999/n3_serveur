<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifie que les routes sensibles (controle, toggle, parameters) ne sont pas
 * dans les chemins publics et que les endpoints critiques sont proteges.
 */
class RoutesConfigSecurityTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $configPath = __DIR__ . '/../config/routes_config.php';
        if (!is_file($configPath)) {
            $this->markTestSkipped('routes_config.php absent');
        }
        $this->config = require $configPath;
    }

    /**
     * Verifie qu'un chemin est considere comme public par le middleware global.
     */
    private function isPublic(string $path): bool
    {
        if (in_array($path, $this->config['exact_public_paths'], true)) {
            return true;
        }
        foreach ($this->config['public_paths'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifie qu'un chemin est considere comme protege par le middleware global.
     */
    private function isProtected(string $path): bool
    {
        foreach ($this->config['protected_paths'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function testToggleRoutesAreNotPublic(): void
    {
        $togglePaths = [
            '/msp1/api/outputs/toggle',
            '/n3pp/api/outputs/toggle',
            '/msp1-test/api/outputs/toggle',
            '/n3pp-test/api/outputs/toggle',
        ];

        foreach ($togglePaths as $path) {
            $this->assertFalse(
                $this->isPublic($path),
                "Route de controle {$path} ne doit PAS etre publique"
            );
        }
    }

    public function testGalleryToggleRoutesAreProtected(): void
    {
        // /gallery est un préfixe public (vitrine), mais les toggles sont listés
        // explicitement dans protected_paths : AuthGuardMiddleware exige l'auth.
        $togglePaths = [
            '/gallery/msp1/api/outputs/toggle',
            '/gallery/n3pp/api/outputs/toggle',
            '/gallery/ffp3/api/outputs/toggle',
        ];

        foreach ($togglePaths as $path) {
            $this->assertTrue(
                $this->requiresAuth($path),
                "Route gallery toggle {$path} doit exiger l'authentification"
            );
        }
    }

    public function testParametersRoutesAreNotPublic(): void
    {
        $paramPaths = [
            '/msp1/api/outputs/parameters',
            '/n3pp/api/outputs/parameters',
        ];

        foreach ($paramPaths as $path) {
            $this->assertFalse(
                $this->isPublic($path),
                "Route parametres {$path} ne doit PAS etre publique"
            );
        }
    }

    public function testDashboardIsProtected(): void
    {
        $this->assertTrue($this->isProtected('/dashboard'));
        $this->assertTrue($this->isProtected('/dashboard-test'));
    }

    public function testSupervisionIsProtected(): void
    {
        $this->assertTrue($this->isProtected('/supervision'));
    }

    public function testFirmwarePostDataIsPublic(): void
    {
        $this->assertTrue($this->isPublic('/post-data'));
        $this->assertTrue($this->isPublic('/heartbeat'));
    }

    public function testRealtimeApiIsPublic(): void
    {
        $this->assertTrue($this->isPublic('/api/realtime'));
        $this->assertTrue($this->isPublic('/msp1/api/realtime/sensors/latest'));
    }

    public function testEnergieRoutesArePublicAndNotProtected(): void
    {
        foreach ([
            '/energie',
            '/energie-test',
            '/energie/post-data',
            '/energie-test/post-data',
            '/energie/api/realtime/sensors/latest',
            '/energie-test/api/realtime/system/health',
        ] as $path) {
            $this->assertTrue($this->isPublic($path), "{$path} doit etre public");
            $this->assertFalse($this->requiresAuth($path), "{$path} ne doit pas exiger d'auth");
        }
    }

    public function testGalleryPagesArePublic(): void
    {
        $this->assertTrue($this->isPublic('/gallery'));
    }

    public function testAdminPathsAreProtected(): void
    {
        $this->assertTrue($this->isProtected('/admin/gallery/msp1'));
    }

    public function testControlPagesAreProtected(): void
    {
        $controlPaths = [
            '/aquaponie-control',
            '/aquaponie-control-test',
            '/aquamobile-control',
            '/meteo-control',
            '/serre-control',
            '/msp1-test/msp1control/',
            '/n3pp-test/n3ppcontrol/',
        ];

        foreach ($controlPaths as $path) {
            $this->assertTrue(
                $this->isProtected($path),
                "Page de controle {$path} doit etre dans protected_paths"
            );
        }
    }

    public function testControlPagesAreNotBypassedByPublicPrefixCollision(): void
    {
        $controlPaths = [
            '/aquaponie-control',
            '/meteo-control',
            '/serre-control',
        ];

        foreach ($controlPaths as $path) {
            $this->assertTrue(
                $this->requiresAuth($path),
                "Page de controle {$path} ne doit pas etre accessible sans auth (collision prefixe public)"
            );
        }
    }

    public function testVitrinePagesDoNotRequireAuth(): void
    {
        $vitrinePaths = ['/aquaponie', '/meteo', '/serre', '/gallery'];

        foreach ($vitrinePaths as $path) {
            $this->assertFalse(
                $this->requiresAuth($path),
                "Page vitrine {$path} doit rester publique"
            );
        }
    }

    /**
     * Reproduit la logique du middleware global : seuls les chemins protected_paths exigent l'auth.
     */
    private function requiresAuth(string $path): bool
    {
        return $this->isProtected($path);
    }

    /**
     * Connecteur MCP : ni chemin public de session, ni chemin protégé par session
     * (l'accès est contrôlé par le jeton OAuth dans McpController ; une session web ne
     * doit donner AUCUN accès à /mcp), et hors du périmètre CSRF de session.
     */
    public function testMcpAndOAuthPathsAreNotSessionRoutes(): void
    {
        foreach (['/mcp', '/oauth/token', '/oauth/authorize', '/oauth/register', '/.well-known/oauth-protected-resource'] as $path) {
            $this->assertFalse($this->isProtected($path), "{$path} ne doit pas exiger de session web");
            foreach ($this->config['public_paths'] as $prefix) {
                $this->assertFalse(str_starts_with($path, $prefix) && $prefix !== '', "{$path} ne doit pas hériter d'un préfixe public ({$prefix})");
            }
        }
        $routes = (string) file_get_contents(__DIR__ . '/../config/routes_mcp.php');
        $this->assertStringContainsString('if (!$mcpConfig->isEnabled())', $routes, 'routes MCP désactivées par défaut');
        $this->assertStringContainsString('->add($mcpRateLimit)', $routes, '/mcp a son limiteur de débit');
        $this->assertStringNotContainsString('applyAuth', $routes, 'aucune auth de session sur /mcp');
    }

    public function testMcpPathsOutsideCsrfPatterns(): void
    {
        $reflection = new \ReflectionClassConstant(\App\Middleware\CsrfMiddleware::class, 'DEFAULT_PROTECTED_PATTERNS');
        foreach ((array) $reflection->getValue() as $pattern) {
            foreach (['/mcp', '/oauth/token', '/oauth/authorize', '/oauth/register', '/oauth/revoke'] as $path) {
                $this->assertSame(0, preg_match((string) $pattern, $path), "{$path} ne doit pas exiger le jeton CSRF de session");
            }
        }
    }

    public function testHtaccessForwardsAuthorizationAndRoutesWellKnown(): void
    {
        $htaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');
        $this->assertStringContainsString('RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]', $htaccess);
        $this->assertMatchesRegularExpression('#RewriteRule \^\\\.well-known/oauth-#', $htaccess);
        $this->assertStringContainsString('RewriteRule ^tools/ - [F,L]', $htaccess, 'blocage tools/ conservé');
        $this->assertStringContainsString('RewriteRule ^vendor/ - [F,L]', $htaccess, 'blocage vendor/ conservé');
        $this->assertStringContainsString('Header always setifempty X-Frame-Options "SAMEORIGIN"', $htaccess, 'n\'écrase pas le DENY des pages OAuth');
        $this->assertStringNotContainsString('Header always set X-Frame-Options', $htaccess);
    }
}
