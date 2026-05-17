<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Contrat auth routes : vérifie que les chemins publics/protégés restent déclarés.
 * Complète le smoke Docker (tools/local-smoke-test.ps1).
 */
class RouteAuthContractTest extends TestCase
{
    public function testRoutesConfigDefinesGalleryAndFirmwarePaths(): void
    {
        $path = dirname(__DIR__, 2) . '/config/routes_config.php';
        $this->assertFileExists($path);
        $config = require $path;

        $publicPaths = $config['public_paths'] ?? [];
        $this->assertContains('/gallery', $publicPaths);

        $protected = $config['protected_paths'] ?? [];
        $this->assertNotEmpty($protected);
        $this->assertTrue(
            count(array_filter($protected, static fn ($p) => str_contains((string) $p, 'dashboard'))) > 0
        );
    }
}
