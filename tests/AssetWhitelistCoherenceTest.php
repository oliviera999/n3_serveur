<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifie la coherence entre les assets references dans les templates Twig
 * et la whitelist dans routes_config.php.
 * Detecte les 404 frontaux previsibles.
 */
class AssetWhitelistCoherenceTest extends TestCase
{
    private array $routesConfig;
    private string $templatesDir;
    private string $assetsDir;

    protected function setUp(): void
    {
        $configPath = __DIR__ . '/../config/routes_config.php';
        if (!is_file($configPath)) {
            $this->markTestSkipped('routes_config.php absent');
        }
        $this->routesConfig = require $configPath;
        $this->templatesDir = __DIR__ . '/../templates';
        $this->assetsDir = __DIR__ . '/../public/assets';
    }

    public function testAllJsAssetsInWhitelistExistOnDisk(): void
    {
        foreach ($this->routesConfig['asset_js'] as $jsFile) {
            $path = $this->assetsDir . '/js/' . $jsFile;
            $this->assertFileExists($path, "JS dans whitelist mais absent du disque: {$jsFile}");
        }
    }

    public function testAllCssAssetsInWhitelistExistOnDisk(): void
    {
        foreach ($this->routesConfig['asset_css'] as $cssFile) {
            $path = $this->assetsDir . '/css/' . $cssFile;
            $this->assertFileExists($path, "CSS dans whitelist mais absent du disque: {$cssFile}");
        }
    }

    public function testTemplateJsReferencesAreInWhitelist(): void
    {
        $whitelist = $this->routesConfig['asset_js'];
        $referenced = $this->extractAssetReferencesFromTemplates('js');

        $missing = array_diff($referenced, $whitelist);
        $this->assertEmpty(
            $missing,
            "JS reference(s) dans les templates mais absente(s) de la whitelist routes_config.php:\n" .
            implode("\n", $missing)
        );
    }

    public function testTemplateCssReferencesAreInWhitelist(): void
    {
        $whitelist = $this->routesConfig['asset_css'];
        $referenced = $this->extractAssetReferencesFromTemplates('css');

        $missing = array_diff($referenced, $whitelist);
        $this->assertEmpty(
            $missing,
            "CSS reference(s) dans les templates mais absente(s) de la whitelist routes_config.php:\n" .
            implode("\n", $missing)
        );
    }

    /**
     * Parcourt les templates Twig et extrait les noms de fichiers assets/js/* ou assets/css/*.
     * @return list<string>
     */
    private function extractAssetReferencesFromTemplates(string $type): array
    {
        $pattern = $type === 'js'
            ? '#/assets/js/([a-zA-Z0-9_.@-]+\.js)#'
            : '#/assets/css/([a-zA-Z0-9_.@-]+\.css)#';

        $files = glob($this->templatesDir . '/*.twig') ?: [];
        $partials = glob($this->templatesDir . '/partials/*.twig') ?: [];
        $allTemplates = array_merge($files, $partials);

        $found = [];
        foreach ($allTemplates as $tpl) {
            $content = file_get_contents($tpl);
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $name) {
                    $found[$name] = true;
                }
            }
        }
        return array_keys($found);
    }
}
