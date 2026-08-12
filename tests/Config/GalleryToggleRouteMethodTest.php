<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou : le toggle galerie ne doit plus accepter GET (CSRF via navigation top-level).
 */
final class GalleryToggleRouteMethodTest extends TestCase
{
    public function testGalleryToggleRouteIsPostOnly(): void
    {
        $routesFile = dirname(__DIR__, 2) . '/config/routes_gallery.php';
        $this->assertFileExists($routesFile);
        $source = (string) file_get_contents($routesFile);

        $this->assertStringNotContainsString(
            "map(['GET', 'POST'], '/gallery/{slug}/api/outputs/toggle'",
            $source,
            'Le toggle galerie ne doit plus accepter GET (CSRF session operator)'
        );
        $this->assertMatchesRegularExpression(
            "#\\\$app->post\\(\\s*'/gallery/\\{slug\\}/api/outputs/toggle'#",
            $source,
            'Le toggle galerie doit être enregistré en POST uniquement'
        );
    }
}
