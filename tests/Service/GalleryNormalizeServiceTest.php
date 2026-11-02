<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Config\Paths;
use App\Service\GalleryNormalizedStore;
use App\Service\GalleryNormalizeService;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires GalleryNormalizeService (algorithme v2).
 *
 * Couverture :
 *  - copie + méta versionnée, anti-retraitement, copie périmée (algorithme antérieur) recalculée ;
 *  - absence de débordement de canal (rampe de gris, primaires saturées, hautes lumières) ;
 *  - photos de nuit laissées intactes ;
 *  - lissage temporel (deflicker) sur une série de photos qui scintille ;
 *  - batch : plus récentes d'abord, plafond, budget de temps ;
 *  - purge : promotion des copies courantes, orphelines supprimées (jamais ressuscitées),
 *    copies périmées ignorées ; EXIF conservé ;
 *  - resolveDisplayPath() : jamais de copie servie quand l'original a disparu.
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

    // ------------------------------------------------------------------
    // Copie, méta, anti-retraitement
    // ------------------------------------------------------------------

    public function testNormalizeCreatesCopyWithMetaAndSkipsSecondRun(): void
    {
        $name = '2026-06-01_12-00-00_cold.jpg';
        // Image froide (B dominant) mais peu saturée → balance des blancs appliquée
        $this->writeSolidJpeg($name, 110, 120, 150);

        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 0.4, 90));
        $normPath = $this->normDir() . '/' . $name;
        self::assertFileExists($normPath);
        self::assertFileExists($this->metaPath($name));
        self::assertTrue($this->service->isAlreadyProcessed(self::SLUG, $name));
        self::assertFalse($this->service->normalizeFile(self::SLUG, $name, 0.4, 90));

        self::assertSame($normPath, $this->service->resolveDisplayPath(self::SLUG, $name));

        $meta = json_decode((string) file_get_contents($this->metaPath($name)), true);
        self::assertIsArray($meta);
        self::assertSame(GalleryNormalizedStore::ALGO_VERSION, $meta['algo']);
        self::assertSame('normalized', $meta['status']);
    }

    public function testStrengthZeroStillWritesNearIdentityCopy(): void
    {
        $name = '2026-06-01_12-00-00_zero.jpg';
        $this->writeSolidJpeg($name, 100, 100, 100);
        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 0.0, 90));
        self::assertFileExists($this->normDir() . '/' . $name);
        $out = $this->pixelAt($this->normDir() . '/' . $name, 20, 20);
        self::assertEqualsWithDelta(100, $out[0], 3);
    }

    public function testStaleCopyFromPreviousAlgorithmIsNotServedAndIsRegenerated(): void
    {
        $name = '2026-06-01_12-00-00_stale.jpg';
        $original = $this->writeTexturedJpeg($name, 120);
        // Copie « v1 » sans méta courante (production antérieure)
        mkdir($this->normDir(), 0755, true);
        copy($original, $this->normDir() . '/' . $name);

        self::assertSame($original, $this->service->resolveDisplayPath(self::SLUG, $name));
        self::assertFalse($this->service->isAlreadyProcessed(self::SLUG, $name));
        self::assertSame(1, $this->service->countPending());

        $result = $this->service->processBatch(5);
        self::assertSame(1, $result['processed']);
        self::assertFileExists($this->metaPath($name));
        self::assertSame($this->normDir() . '/' . $name, $this->service->resolveDisplayPath(self::SLUG, $name));
    }

    public function testResolveDisplayPathReturnsNullWhenOriginalIsGone(): void
    {
        $name = '2026-06-01_12-00-00_gone.jpg';
        $original = $this->writeTexturedJpeg($name, 120);
        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 0.5, 90));

        unlink($original); // photo mise en corbeille / supprimée
        self::assertNull($this->service->resolveDisplayPath(self::SLUG, $name));
    }

    // ------------------------------------------------------------------
    // Exactitude colorimétrique (régressions v1 : débordements de canal)
    // ------------------------------------------------------------------

    public function testGrayRampStaysMonotonicAtFullStrength(): void
    {
        $name = '2026-06-01_12-00-00_ramp.jpg';
        $img = imagecreatetruecolor(256, 24);
        for ($x = 0; $x < 256; $x++) {
            imageline($img, $x, 0, $x, 23, ($x << 16) | ($x << 8) | $x);
        }
        imagejpeg($img, $this->galleryDir() . '/' . $name, 95);
        imagedestroy($img);

        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 1.0, 95));
        $copy = $this->normDir() . '/' . $name;

        $previous = -1.0;
        for ($x = 0; $x < 256; $x += 8) {
            [$r, $g, $b] = $this->pixelAt($copy, $x, 12);
            $luma = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            // Un débordement (v1) faisait retomber les hautes lumières vers le noir.
            self::assertGreaterThanOrEqual($previous - 4.0, $luma, "Rampe non monotone en x={$x}");
            $previous = $luma;
        }
        self::assertGreaterThan(230.0, $previous, 'Le blanc doit rester blanc');
    }

    public function testSaturatedColorsAndHighlightsAreNotCorrupted(): void
    {
        $name = '2026-06-01_12-00-00_colors.jpg';
        $img = imagecreatetruecolor(200, 150);
        for ($y = 0; $y < 150; $y++) {
            for ($x = 0; $x < 200; $x++) {
                $v = 60 + (($x * 3 + $y * 5) % 120);
                imagesetpixel($img, $x, $y, ((int) ($v * 0.6) << 16) | ($v << 8) | (int) ($v * 0.4));
            }
        }
        imagefilledrectangle($img, 0, 0, 16, 16, 0xFFFFFF);       // reflet blanc ~1 %
        imagefilledrectangle($img, 180, 130, 196, 146, 0xE02020); // fleur rouge ~1 %
        imagefilledrectangle($img, 100, 130, 116, 146, 0x2020E0); // objet bleu ~1 %
        imagejpeg($img, $this->galleryDir() . '/' . $name, 95);
        imagedestroy($img);

        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 1.0, 95));
        $copy = $this->normDir() . '/' . $name;

        [$r, $g, $b] = $this->pixelAt($copy, 8, 8);
        self::assertGreaterThan(235, min($r, $g, $b), 'Reflet blanc assombri');

        [$r, $g, $b] = $this->pixelAt($copy, 188, 138);
        self::assertGreaterThan(180, $r, 'Rouge perdu');
        self::assertLessThan(90, max($g, $b), 'Rouge dénaturé');

        [$r, $g, $b] = $this->pixelAt($copy, 108, 138);
        self::assertGreaterThan(180, $b, 'Bleu perdu');
        self::assertLessThan(90, max($r, $g), 'Bleu dénaturé');
    }

    public function testDarkFrameIsLeftUntouched(): void
    {
        $name = '2026-06-01_02-00-00_night.jpg';
        $img = imagecreatetruecolor(120, 90);
        for ($y = 0; $y < 90; $y++) {
            for ($x = 0; $x < 120; $x++) {
                $v = 8 + (($x * 7 + $y * 13) % 18);
                imagesetpixel($img, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }
        $original = $this->galleryDir() . '/' . $name;
        imagejpeg($img, $original, 95);
        imagedestroy($img);

        self::assertFalse($this->service->normalizeFile(self::SLUG, $name, 0.35, 90));
        self::assertFileDoesNotExist($this->normDir() . '/' . $name);
        self::assertFileExists($this->metaPath($name));
        self::assertTrue($this->service->isAlreadyProcessed(self::SLUG, $name));
        self::assertSame($original, $this->service->resolveDisplayPath(self::SLUG, $name));
        self::assertSame(0, $this->service->countPending());

        $meta = json_decode((string) file_get_contents($this->metaPath($name)), true);
        self::assertIsArray($meta);
        self::assertSame('skipped_dark', $meta['status']);
    }

    // ------------------------------------------------------------------
    // Lissage temporel
    // ------------------------------------------------------------------

    public function testTemporalSmoothingReducesFlicker(): void
    {
        $names = [];
        foreach ([100, 140, 100, 140, 100] as $i => $base) {
            $name = sprintf('2026-06-01_12-%02d-00_f%d.jpg', $i * 10, $i);
            $this->writeTexturedJpeg($name, $base);
            $names[] = $name;
        }

        $result = $this->service->processBatch(10, 60.0);
        self::assertSame(5, $result['processed']);

        $in = [];
        $out = [];
        foreach ($names as $name) {
            $in[] = $this->meanLuma($this->galleryDir() . '/' . $name);
            $out[] = $this->meanLuma((string) $this->service->resolveDisplayPath(self::SLUG, $name));
        }
        self::assertLessThan(
            $this->meanJitter($in) * 0.8,
            $this->meanJitter($out),
            'Le scintillement entre photos successives doit diminuer'
        );

        $meta = json_decode((string) file_get_contents($this->metaPath($names[2])), true);
        self::assertIsArray($meta);
        self::assertSame(5, $meta['params']['smoothing_window']);
    }

    public function testIsolatedPhotoIsNotSmoothedWithDistantNeighbors(): void
    {
        $this->writeTexturedJpeg('2026-06-01_06-00-00_a.jpg', 100);
        $this->writeTexturedJpeg('2026-06-01_12-00-00_b.jpg', 140); // > 2 h d'écart

        self::assertTrue($this->service->normalizeFile(self::SLUG, '2026-06-01_12-00-00_b.jpg', 0.35, 90));
        $meta = json_decode((string) file_get_contents($this->metaPath('2026-06-01_12-00-00_b.jpg')), true);
        self::assertIsArray($meta);
        self::assertSame(1, $meta['params']['smoothing_window']);
    }

    // ------------------------------------------------------------------
    // Batch
    // ------------------------------------------------------------------

    public function testProcessBatchRespectsMaxAndDoesNotReprocess(): void
    {
        $this->writeTexturedJpeg('2026-06-01_10-00-00_a.jpg', 90);
        $this->writeTexturedJpeg('2026-06-01_11-00-00_b.jpg', 110);
        $this->writeTexturedJpeg('2026-06-01_12-00-00_c.jpg', 130);

        $first = $this->service->processBatch(2, 60.0);
        self::assertSame(3, $first['pending']);
        self::assertSame(2, $first['processed']);

        $second = $this->service->processBatch(10, 60.0);
        self::assertSame(1, $second['pending']);
        self::assertSame(1, $second['processed']);

        $third = $this->service->processBatch(10, 60.0);
        self::assertSame(0, $third['pending']);
        self::assertSame(0, $third['processed']);
    }

    public function testProcessBatchHandlesNewestFirst(): void
    {
        $this->writeTexturedJpeg('2026-06-01_10-00-00_old.jpg', 120);
        $this->writeTexturedJpeg('2026-06-01_11-00-00_mid.jpg', 120);
        $this->writeTexturedJpeg('2026-06-01_12-00-00_new.jpg', 120);

        $result = $this->service->processBatch(1, 60.0);
        self::assertSame(1, $result['processed']);
        self::assertTrue($this->service->isAlreadyProcessed(self::SLUG, '2026-06-01_12-00-00_new.jpg'));
        self::assertFalse($this->service->isAlreadyProcessed(self::SLUG, '2026-06-01_10-00-00_old.jpg'));
    }

    public function testProcessBatchStopsOnTimeBudgetButAlwaysProgresses(): void
    {
        $this->writeTexturedJpeg('2026-06-01_10-00-00_a.jpg', 120);
        $this->writeTexturedJpeg('2026-06-01_11-00-00_b.jpg', 120);
        $this->writeTexturedJpeg('2026-06-01_12-00-00_c.jpg', 120);

        $result = $this->service->processBatch(10, 0.0);
        self::assertSame(1, $result['processed']);
        self::assertTrue($result['timed_out']);
        self::assertSame(2, $this->service->countPending());
    }

    // ------------------------------------------------------------------
    // Purge des originaux
    // ------------------------------------------------------------------

    public function testPurgePromotesNormalizedAndMarksDone(): void
    {
        $name = '2026-06-01_12-00-00_purge.jpg';
        $original = $this->writeTexturedJpeg($name, 110);
        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 0.5, 90));
        $normBefore = $this->normDir() . '/' . $name;
        $normalizedBytes = (string) file_get_contents($normBefore);

        $result = $this->service->purgeNormalizedOriginals(self::SLUG);
        self::assertSame(1, $result['purged']);
        self::assertFileExists($original);
        self::assertSame($normalizedBytes, file_get_contents($original));
        self::assertFileDoesNotExist($normBefore);
        self::assertFileExists($this->normDir() . '/' . $name . '.done');
        self::assertTrue($this->service->isAlreadyProcessed(self::SLUG, $name));
        self::assertSame(0, $this->service->countPending());
        self::assertSame($original, $this->service->resolveDisplayPath(self::SLUG, $name));
    }

    public function testPurgeRemovesOrphanCopiesInsteadOfResurrectingThem(): void
    {
        $name = '2026-06-01_12-00-00_trashed.jpg';
        $original = $this->writeTexturedJpeg($name, 110);
        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 0.5, 90));
        unlink($original); // ex. mise en corbeille sans nettoyage (données antérieures)

        $result = $this->service->purgeNormalizedOriginals(self::SLUG);
        self::assertSame(0, $result['purged']);
        self::assertSame(1, $result['orphans_removed']);
        self::assertFileDoesNotExist($original);
        self::assertFileDoesNotExist($this->normDir() . '/' . $name);
        self::assertFileDoesNotExist($this->metaPath($name));
    }

    public function testPurgeIgnoresStaleCopies(): void
    {
        $name = '2026-06-01_12-00-00_stale.jpg';
        $original = $this->writeTexturedJpeg($name, 110);
        $originalBytes = (string) file_get_contents($original);
        mkdir($this->normDir(), 0755, true);
        file_put_contents($this->normDir() . '/' . $name, 'copie v1');

        $result = $this->service->purgeNormalizedOriginals();
        self::assertSame(0, $result['purged']);
        self::assertSame(1, $result['stale']);
        self::assertSame($originalBytes, file_get_contents($original));
    }

    public function testPurgeRejectsUnknownSlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->purgeNormalizedOriginals('inconnu');
    }

    // ------------------------------------------------------------------
    // EXIF
    // ------------------------------------------------------------------

    public function testExifSegmentsArePreservedInCopy(): void
    {
        $name = '2026-06-01_12-00-00_exif.jpg';
        $path = $this->writeTexturedJpeg($name, 120);

        // APP1 « Exif » minimal : en-tête TIFF big-endian + IFD0 vide.
        $payload = "Exif\x00\x00" . "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x00" . "\x00\x00\x00\x00";
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        file_put_contents($path, GalleryNormalizeService::injectSegments((string) file_get_contents($path), $segment));
        self::assertSame($segment, GalleryNormalizeService::extractExifSegments((string) file_get_contents($path)));

        self::assertTrue($this->service->normalizeFile(self::SLUG, $name, 0.5, 90));
        $copyBytes = (string) file_get_contents($this->normDir() . '/' . $name);
        self::assertSame($segment, GalleryNormalizeService::extractExifSegments($copyBytes));
        self::assertNotFalse(@imagecreatefromstring($copyBytes), 'La copie doit rester un JPEG décodable');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function galleryDir(): string
    {
        return $this->root . '/uploads/' . self::SLUG;
    }

    private function normDir(): string
    {
        return $this->galleryDir() . '/.normalized';
    }

    private function metaPath(string $name): string
    {
        return $this->normDir() . '/' . $name . '.v' . GalleryNormalizedStore::ALGO_VERSION . '.json';
    }

    private function writeSolidJpeg(string $filename, int $r, int $g, int $b, int $size = 40): string
    {
        $path = $this->galleryDir() . '/' . $filename;
        $img = imagecreatetruecolor($size, $size);
        $color = (int) imagecolorallocate($img, $r, $g, $b);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $color);
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return $path;
    }

    /**
     * Scène grise texturée (luma ≈ $base ± 40) : histogramme réaliste, non sombre.
     */
    private function writeTexturedJpeg(string $filename, int $base): string
    {
        $path = $this->galleryDir() . '/' . $filename;
        $img = imagecreatetruecolor(160, 120);
        for ($y = 0; $y < 120; $y++) {
            for ($x = 0; $x < 160; $x++) {
                $v = max(0, min(255, $base - 40 + (($x * 7 + $y * 3) % 80)));
                imagesetpixel($img, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }
        imagejpeg($img, $path, 95);
        imagedestroy($img);

        return $path;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function pixelAt(string $path, int $x, int $y): array
    {
        $img = imagecreatefromjpeg($path);
        self::assertNotFalse($img);
        $rgb = imagecolorat($img, $x, $y);
        imagedestroy($img);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    private function meanLuma(string $path): float
    {
        $img = imagecreatefromjpeg($path);
        self::assertNotFalse($img);
        $sum = 0.0;
        $n = 0;
        for ($y = 0; $y < imagesy($img); $y += 4) {
            for ($x = 0; $x < imagesx($img); $x += 4) {
                $rgb = imagecolorat($img, $x, $y);
                $sum += 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                $n++;
            }
        }
        imagedestroy($img);

        return $sum / max(1, $n);
    }

    /**
     * @param list<float> $values
     */
    private function meanJitter(array $values): float
    {
        $sum = 0.0;
        for ($i = 1, $n = count($values); $i < $n; $i++) {
            $sum += abs($values[$i] - $values[$i - 1]);
        }

        return $sum / max(1, count($values) - 1);
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
