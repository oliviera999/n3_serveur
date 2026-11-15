<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\CacheDirectoryPurger;
use PHPUnit\Framework\TestCase;

final class CacheDirectoryPurgerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/purger_' . uniqid('', true);
        mkdir($this->tmp . '/twig/86', 0775, true);
        mkdir($this->tmp . '/twig/ab', 0775, true);
        file_put_contents($this->tmp . '/twig/86/86718badaa41.php', '<?php');
        file_put_contents($this->tmp . '/twig/ab/ab12.php', '<?php');
    }

    protected function tearDown(): void
    {
        foreach ([$this->tmp . '/twig/86', $this->tmp . '/twig/ab', $this->tmp . '/twig', $this->tmp . '/absent', $this->tmp] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
    }

    /**
     * Les fichiers partent, l'arborescence reste : un dossier de hachage n'est jamais retiré sous
     * les pieds d'une requête qui vient de le créer pour y écrire son template compilé.
     */
    public function testDeletesFilesButKeepsDirectories(): void
    {
        $r = CacheDirectoryPurger::purge($this->tmp . '/twig');

        $this->assertSame(2, $r['deleted']);
        $this->assertSame(0, $r['failed']);
        $this->assertDirectoryExists($this->tmp . '/twig/86');
        $this->assertDirectoryExists($this->tmp . '/twig/ab');
        $this->assertSame([], glob($this->tmp . '/twig/*/*.php') ?: []);
    }

    public function testRecreatesMissingRoot(): void
    {
        $r = CacheDirectoryPurger::purge($this->tmp . '/absent');

        $this->assertTrue($r['created']);
        $this->assertDirectoryExists($this->tmp . '/absent');
    }
}
