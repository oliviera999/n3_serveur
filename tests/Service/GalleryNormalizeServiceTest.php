<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Config\Paths;
use App\Service\GalleryNormalizeService;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires GalleryNormalizeService (copies .normalized/, anti-retraitement, purge).
 */
final class GalleryNormalizeServiceTest extends TestCase
{
    private const SLUG = 'msp1';

    private string $root;
    private GalleryNormalizeService $service;
    private ?string $previousRoot = null;

    protected function setUp(): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagejpeg')) {
            self::markTestSkipped('Extension GD requise pour GalleryNormalizeService');
        }

        $this->root = sys_get_temp_dir() . '/gallery_normalize_test_' . uniqid('', true);
        mkdir($this->galleryDir(), 0755, true);

        $this->previousRoot = Paths::getProjectRoot();
        Paths::setProjectRoot($this->root);

        putenv('LOG_FILE_PATH=php://memory');

        $logger = $this->createMock(LogService::class);
        $this->service = new GalleryNormalizeService($logger);
    }

    protected function tearDown(): void
    {
        Paths::setProjectRoot($this->previousRoot);
        $this->removeDir($this->root);
    }

    private function galleryDir(): string
    {
        return $this->root . '/uploads/' . self::SLUG;
    }

    private function normDir(): string
    {
        return $this->galleryDir() . '/.normalized';
    }

    private function writeSolidJpeg(string $filename, int $r, int $g, int $b, int $size = 40): string
    {
        $path = $this->galleryDir() . '/' . $filename;
        $img = imagecreatetruecolor($size, $size);
        $color = imagecolorallocate($img, $r, $g, $b);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $color);
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return $path;
    }

    public function testNormalizeCreatesSidecarAndSkipsSecondRun(): void
    {
        $name = '2026-06-01_12-00-00_cold.jpg';
        // Image froide (B dominant) pour forcer un peu de WB
        $this->writeSolidJpeg($name, 80, 90, 160);

        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 0.4, 90));
        $normPath = $this->normDir() . '/' . $name;
        self::assertFileExists($normPath);
        self::assertTrue($this->service->isAlreadyProcessed(self::SLUG, $name));
        self::assertFalse($this->service->normalizeFile(self::SLUG, $name, 0.4, 90));

        $display = $this->service->resolveDisplayPath(self::SLUG, $name);
        self::assertSame($normPath, $display);
    }

    public function testProcessBatchRespectsMaxAndDoesNotReprocess(): void
    {
        $this->writeSolidJpeg('a_2026-06-01_10-00-00.jpg', 40, 40, 40);
        $this->writeSolidJpeg('b_2026-06-01_11-00-00.jpg', 50, 60, 120);
        $this->writeSolidJpeg('c_2026-06-01_12-00-00.jpg', 100, 100, 100);

        $first = $this->service->processBatch(2);
        self::assertSame(3, $first['pending']);
        self::assertSame(2, $first['processed']);

        $second = $this->service->processBatch(10);
        self::assertSame(1, $second['pending']);
        self::assertSame(1, $second['processed']);

        $third = $this->service->processBatch(10);
        self::assertSame(0, $third['pending']);
        self::assertSame(0, $third['processed']);
    }

    public function testStrengthZeroStillWritesNearIdentityCopy(): void
    {
        $name = '2026-06-01_12-00-00_zero.jpg';
        $this->writeSolidJpeg($name, 100, 100, 100);
        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 0.0, 90));
        self::assertFileExists($this->normDir() . '/' . $name);
    }

    public function testPurgePromotesNormalizedAndMarksDone(): void
    {
        $name = '2026-06-01_12-00-00_purge.jpg';
        $original = $this->writeSolidJpeg($name, 30, 40, 140);
        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 0.5, 90));
        $normBefore = $this->normDir() . '/' . $name;
        self::assertFileExists($normBefore);

        $result = $this->service->purgeNormalizedOriginals(self::SLUG);
        self::assertSame(1, $result['purged']);
        self::assertFileExists($original);
        self::assertFileDoesNotExist($normBefore);
        self::assertFileExists($this->normDir() . '/' . $name . '.done');
        self::assertTrue($this->service->isAlreadyProcessed(self::SLUG, $name));
        self::assertSame(0, $this->service->countPending());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
