<?php

declare(strict_types=1);

namespace Tests\Controller\Gallery;

use App\Controller\Gallery\GalleryViewController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tri galerie : date de capture prioritaire sur le compteur seq.
 */
class GalleryViewSortTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gallery_sort_test_' . bin2hex(random_bytes(3));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testListImageFilesPrefersCaptureDateOverSeq(): void
    {
        foreach ([
            '0000000460_2026-07-06_13-19-56_aaaa.jpg',
            '0000000037_2026-07-12_10-35-16_bbbb.jpg',
            '0000000100_2026-07-11_09-00-00_cccc.jpg',
            'notes.txt',
        ] as $name) {
            file_put_contents($this->dir . '/' . $name, 'x');
        }

        $files = $this->listImageFiles();

        $this->assertSame([
            '0000000037_2026-07-12_10-35-16_bbbb.jpg',
            '0000000100_2026-07-11_09-00-00_cccc.jpg',
            '0000000460_2026-07-06_13-19-56_aaaa.jpg',
        ], $files);
    }

    public function testEqualCaptureDatesFallBackToSeqDescending(): void
    {
        foreach ([
            '0000000005_2026-07-12_10-35-16_aaaa.jpg',
            '0000000009_2026-07-12_10-35-16_bbbb.jpg',
        ] as $name) {
            file_put_contents($this->dir . '/' . $name, 'x');
        }

        $this->assertSame([
            '0000000009_2026-07-12_10-35-16_bbbb.jpg',
            '0000000005_2026-07-12_10-35-16_aaaa.jpg',
        ], $this->listImageFiles());
    }

    /**
     * @return list<string>
     */
    private function listImageFiles(): array
    {
        $ref = new ReflectionClass(GalleryViewController::class);
        /** @var GalleryViewController $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(GalleryViewController::class, 'listImageFiles');
        $method->setAccessible(true);
        /** @var list<string> $files */
        $files = $method->invoke($instance, $this->dir);

        return $files;
    }
}
