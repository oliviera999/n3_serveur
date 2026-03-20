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

    public function testGalleryPagesArePublic(): void
    {
        $this->assertTrue($this->isPublic('/gallery'));
    }

    public function testAdminPathsAreProtected(): void
    {
        $this->assertTrue($this->isProtected('/admin/gallery/msp1'));
    }
}
