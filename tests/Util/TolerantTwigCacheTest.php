<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Service\LogService;
use App\Service\TemplateRenderer;
use App\Util\TolerantTwigCache;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Incident du 14/11/2026 : `GET /serre` → 500 « Failed to write cache file … var/cache/twig/86/… ».
 * Un échec d'écriture du cache Twig doit donner une page rendue sans cache + un WARNING, jamais une 500.
 */
final class TolerantTwigCacheTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/twig_tolerant_' . uniqid('', true);
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->tmp);
    }

    public function testWriteFailureRendersWithoutCacheAndWarnsOnce(): void
    {
        // Racine du cache = un FICHIER : tout mkdir de sous-dossier de hachage échoue (même en root).
        $blocked = $this->tmp . '/cache-is-a-file';
        file_put_contents($blocked, 'x');

        $logger = $this->createMock(LogService::class);
        // 2 WARNING : la page, puis la clé « X » (écrite deux fois, signalée une seule fois).
        $logger->expects($this->exactly(2))->method('warning')
            ->with($this->stringContains('[twig-cache] écriture du cache impossible'));

        $cache = new TolerantTwigCache($blocked, $logger);
        $twig = new Environment(new ArrayLoader(['page.twig' => 'Bonjour {{ nom }}']), ['cache' => $cache]);

        $this->assertSame('Bonjour serre', $twig->render('page.twig', ['nom' => 'serre']));
        $cache->write($cache->generateKey('page.twig', 'X'), '<?php');
        $cache->write($cache->generateKey('page.twig', 'X'), '<?php');
    }

    public function testSuccessfulWriteStillCaches(): void
    {
        $cache = new TolerantTwigCache($this->tmp . '/ok');
        $twig = new Environment(new ArrayLoader(['p.twig' => 'ok']), ['cache' => $cache]);
        $this->assertSame('ok', $twig->render('p.twig'));
        $files = glob($this->tmp . '/ok/*/*.php') ?: [];
        $this->assertCount(1, $files, 'template compilé écrit dans le cache');
    }

    /**
     * Bout en bout avec le vrai TemplateRenderer : chaque sous-dossier de hachage possible est
     * bloqué (fichier au lieu d'un dossier) — la page d'erreur se rend quand même.
     */
    public function testTemplateRendererSurvivesUnwritableHashSubdirectory(): void
    {
        $cacheDir = $this->tmp . '/twig';
        mkdir($cacheDir);
        for ($i = 0; $i < 256; $i++) {
            file_put_contents($cacheDir . '/' . sprintf('%02x', $i), '');
        }
        $previous = $_ENV['TWIG_CACHE_DIR'] ?? null;
        $_ENV['TWIG_CACHE_DIR'] = $cacheDir;
        try {
            $logger = $this->createMock(LogService::class);
            $logger->expects($this->atLeastOnce())->method('warning');
            $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates', true, null, null, null, $logger);
            $html = $renderer->render('error_page.twig', ['heading' => 'Titre test', 'message' => 'Corps', 'nav_active' => 'home']);
        } finally {
            if ($previous === null) {
                unset($_ENV['TWIG_CACHE_DIR']);
            } else {
                $_ENV['TWIG_CACHE_DIR'] = $previous;
            }
        }
        $this->assertStringContainsString('Titre test', $html);
    }

    private function rm(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->rm($path . '/' . $name);
            }
        }
        @rmdir($path);
    }
}
