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
    public function testSortNewestFirstPrefersCaptureDateOverSeq(): void
    {
        $ref = new ReflectionClass(GalleryViewController::class);
        /** @var GalleryViewController $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        $files = [
            '0000000460_2026-07-06_13-19-56_aaaa.jpg',
            '0000000037_2026-07-12_10-35-16_bbbb.jpg',
            '0000000100_2026-07-11_09-00-00_cccc.jpg',
        ];
        $dir = sys_get_temp_dir();

        $sort = new ReflectionMethod(GalleryViewController::class, 'sortNewestFirst');
        $sort->setAccessible(true);
        $sort->invokeArgs($instance, [&$files, $dir]);

        $this->assertSame('0000000037_2026-07-12_10-35-16_bbbb.jpg', $files[0]);
        $this->assertSame('0000000100_2026-07-11_09-00-00_cccc.jpg', $files[1]);
        $this->assertSame('0000000460_2026-07-06_13-19-56_aaaa.jpg', $files[2]);
    }
}
