<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Config\GalleryConfig;
use App\Config\Paths;
use App\Service\GalleryTrashService;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de GalleryTrashService.
 *
 * Toutes les opérations de fichiers sont isolées dans un répertoire temporaire dédié :
 * Paths::setProjectRoot() (couture de test DÉJÀ prévue par la prod) redirige la racine
 * projet vers sys_get_temp_dir(), de sorte que ni les galeries d'upload réelles
 * (uploads/{slug}) ni la corbeille réelle (uploads/trash/{slug}) ne sont touchées.
 * Le LogService est mocké pour ne rien écrire et vérifier le passage par les branches
 * d'erreur quand c'est pertinent.
 *
 * Couverture :
 *  - analyzeImage() : décision qualité image (ok / too_bright / too_dark / corrupted) via
 *    des JPEG générés par GD ; cas fichier manquant et fichier non décodable ;
 *  - moveToTrash() / restore() / purge() : cycle de vie d'une photo dans la corbeille,
 *    avec persistance/lecture des métadonnées JSON ;
 *  - listTrashed(), purgeSelected(), purgeAll(), countAllTrashed(), getTrashImagePath() ;
 *  - autoSortGallery() : tri automatique (déplace les images défectueuses, conserve les
 *    images correctes, compte par motif) ;
 *  - validation slug / nom de fichier (InvalidArgumentException).
 */
final class GalleryTrashServiceTest extends TestCase
{
    private const SLUG = 'msp1';

    private string $root;
    private GalleryTrashService $service;
    /** @var LogService&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;
    private ?string $previousRoot = null;

    protected function setUp(): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagejpeg')) {
            self::markTestSkipped('Extension GD requise pour GalleryTrashService');
        }

        $this->root = sys_get_temp_dir() . '/gallery_trash_test_' . uniqid('', true);
        mkdir($this->galleryDir(), 0755, true);

        // Sauvegarde de la racine courante puis redirection vers le dossier temporaire.
        $this->previousRoot = Paths::getProjectRoot();
        Paths::setProjectRoot($this->root);

        putenv('LOG_FILE_PATH=php://memory');

        $this->logger = $this->createMock(LogService::class);
        $this->service = new GalleryTrashService($this->logger);
    }

    protected function tearDown(): void
    {
        // Restaure la racine projet pour ne pas polluer les autres tests.
        Paths::setProjectRoot($this->previousRoot);
        $this->removeDir($this->root);
    }

    private function galleryDir(): string
    {
        return $this->root . '/uploads/' . self::SLUG;
    }

    private function trashDir(): string
    {
        return $this->root . '/uploads/trash/' . self::SLUG;
    }

    /**
     * Génère un JPEG monochrome de luminosité contrôlée (composantes R=G=B=$value).
     * La luminosité perçue calculée par le service vaut alors exactement $value.
     */
    private function makeJpeg(string $path, int $value, int $width = 40, int $height = 40): void
    {
        $img = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($img, $value, $value, $value);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $color);
        imagejpeg($img, $path);
        imagedestroy($img);
    }

    // ------------------------------------------------------------------
    // analyzeImage : décision qualité
    // ------------------------------------------------------------------

    public function testAnalyzeImageReturnsOkForMidBrightness(): void
    {
        $path = $this->galleryDir() . '/gray.jpg';
        $this->makeJpeg($path, 128);

        $result = $this->service->analyzeImage($path);

        self::assertSame('ok', $result['quality']);
        self::assertNull($result['reason']);
        self::assertIsFloat($result['brightness']);
        self::assertEqualsWithDelta(128.0, $result['brightness'], 1.0);
    }

    public function testAnalyzeImageDetectsOverexposure(): void
    {
        $path = $this->galleryDir() . '/white.jpg';
        $this->makeJpeg($path, 255);

        $result = $this->service->analyzeImage($path);

        self::assertSame('too_bright', $result['quality']);
        self::assertNotNull($result['reason']);
    }

    public function testAnalyzeImageDetectsUnderexposure(): void
    {
        $path = $this->galleryDir() . '/black.jpg';
        $this->makeJpeg($path, 0);

        $result = $this->service->analyzeImage($path);

        self::assertSame('too_dark', $result['quality']);
        self::assertNotNull($result['reason']);
    }

    public function testAnalyzeImageReturnsCorruptedForMissingFile(): void
    {
        $result = $this->service->analyzeImage($this->galleryDir() . '/does_not_exist.jpg');

        self::assertSame('corrupted', $result['quality']);
        self::assertNull($result['brightness']);
    }

    public function testAnalyzeImageReturnsCorruptedForNonJpegContent(): void
    {
        $path = $this->galleryDir() . '/broken.jpg';
        file_put_contents($path, 'ceci n est pas un jpeg');

        $result = $this->service->analyzeImage($path);

        self::assertSame('corrupted', $result['quality']);
    }

    public function testAnalyzeImageReturnsCorruptedForTooSmallImage(): void
    {
        $path = $this->galleryDir() . '/tiny.jpg';
        $this->makeJpeg($path, 128, 5, 5);

        $result = $this->service->analyzeImage($path);

        self::assertSame('corrupted', $result['quality']);
        self::assertStringContainsString('trop petite', (string) $result['reason']);
    }

    // ------------------------------------------------------------------
    // Cycle de vie corbeille
    // ------------------------------------------------------------------

    public function testMoveToTrashMovesFileAndWritesMetadata(): void
    {
        $this->makeJpeg($this->galleryDir() . '/photo.jpg', 200);

        $moved = $this->service->moveToTrash(self::SLUG, 'photo.jpg', 'too_bright');

        self::assertTrue($moved);
        self::assertFileDoesNotExist($this->galleryDir() . '/photo.jpg');
        self::assertFileExists($this->trashDir() . '/photo.jpg');

        $listed = $this->service->listTrashed(self::SLUG);
        self::assertCount(1, $listed);
        self::assertSame('photo.jpg', $listed[0]['filename']);
        self::assertSame('too_bright', $listed[0]['reason']);
        self::assertSame(self::SLUG, $listed[0]['original_slug']);
        self::assertNotNull($listed[0]['trashed_at']);
    }

    public function testMoveToTrashReturnsFalseWhenSourceMissing(): void
    {
        $moved = $this->service->moveToTrash(self::SLUG, 'ghost.jpg', 'manual');

        self::assertFalse($moved);
    }

    public function testRestoreMovesFileBackToGallery(): void
    {
        $this->makeJpeg($this->galleryDir() . '/photo.jpg', 200);
        $this->service->moveToTrash(self::SLUG, 'photo.jpg', 'manual');

        $restored = $this->service->restore(self::SLUG, 'photo.jpg');

        self::assertTrue($restored);
        self::assertFileExists($this->galleryDir() . '/photo.jpg');
        self::assertFileDoesNotExist($this->trashDir() . '/photo.jpg');
        self::assertCount(0, $this->service->listTrashed(self::SLUG));
    }

    public function testRestoreReturnsFalseWhenNotInTrash(): void
    {
        self::assertFalse($this->service->restore(self::SLUG, 'photo.jpg'));
    }

    public function testPurgeRemovesFilePermanently(): void
    {
        $this->makeJpeg($this->galleryDir() . '/photo.jpg', 200);
        $this->service->moveToTrash(self::SLUG, 'photo.jpg', 'manual');

        $purged = $this->service->purge(self::SLUG, 'photo.jpg');

        self::assertTrue($purged);
        self::assertFileDoesNotExist($this->trashDir() . '/photo.jpg');
        self::assertCount(0, $this->service->listTrashed(self::SLUG));
    }

    public function testPurgeReturnsFalseWhenFileAbsent(): void
    {
        self::assertFalse($this->service->purge(self::SLUG, 'photo.jpg'));
    }

    public function testPurgeSelectedCountsDeletedAndFailed(): void
    {
        $this->makeJpeg($this->galleryDir() . '/a.jpg', 200);
        $this->makeJpeg($this->galleryDir() . '/b.jpg', 200);
        $this->service->moveToTrash(self::SLUG, 'a.jpg', 'manual');
        $this->service->moveToTrash(self::SLUG, 'b.jpg', 'manual');

        $result = $this->service->purgeSelected(self::SLUG, ['a.jpg', 'absent.jpg']);

        self::assertSame(1, $result['deleted']);
        self::assertSame(1, $result['failed']);
        self::assertCount(1, $this->service->listTrashed(self::SLUG));
    }

    public function testPurgeAllEmptiesTheTrash(): void
    {
        $this->makeJpeg($this->galleryDir() . '/a.jpg', 200);
        $this->makeJpeg($this->galleryDir() . '/b.jpg', 200);
        $this->service->moveToTrash(self::SLUG, 'a.jpg', 'manual');
        $this->service->moveToTrash(self::SLUG, 'b.jpg', 'manual');

        $result = $this->service->purgeAll(self::SLUG);

        self::assertSame(2, $result['deleted']);
        self::assertSame(0, $result['failed']);
        self::assertCount(0, $this->service->listTrashed(self::SLUG));
    }

    public function testListTrashedReturnsEmptyArrayWhenNoTrashDir(): void
    {
        // Aucune photo déplacée -> le dossier corbeille n'existe pas encore.
        self::assertSame([], $this->service->listTrashed(self::SLUG));
    }

    public function testGetTrashImagePathReturnsPathWhenPresentAndNullOtherwise(): void
    {
        $this->makeJpeg($this->galleryDir() . '/photo.jpg', 200);
        $this->service->moveToTrash(self::SLUG, 'photo.jpg', 'manual');

        self::assertSame(
            $this->trashDir() . '/photo.jpg',
            $this->service->getTrashImagePath(self::SLUG, 'photo.jpg')
        );
        self::assertNull($this->service->getTrashImagePath(self::SLUG, 'missing.jpg'));
    }

    public function testCountAllTrashedReturnsCountPerSlug(): void
    {
        $this->makeJpeg($this->galleryDir() . '/photo.jpg', 200);
        $this->service->moveToTrash(self::SLUG, 'photo.jpg', 'manual');

        $counts = $this->service->countAllTrashed();

        self::assertSame(GalleryConfig::getAllowedSlugs(), array_keys($counts));
        self::assertSame(1, $counts[self::SLUG]);
    }

    // ------------------------------------------------------------------
    // Tri automatique
    // ------------------------------------------------------------------

    public function testAutoSortGalleryMovesDefectiveAndKeepsGood(): void
    {
        $this->makeJpeg($this->galleryDir() . '/good.jpg', 128);   // ok -> conservée
        $this->makeJpeg($this->galleryDir() . '/bright.jpg', 255); // too_bright -> corbeille
        $this->makeJpeg($this->galleryDir() . '/dark.jpg', 0);     // too_dark -> corbeille

        $result = $this->service->autoSortGallery(self::SLUG);

        self::assertSame(3, $result['scanned']);
        self::assertSame(2, $result['moved']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['failed']);
        self::assertSame(1, $result['by_reason']['too_bright'] ?? 0);
        self::assertSame(1, $result['by_reason']['too_dark'] ?? 0);

        self::assertFileExists($this->galleryDir() . '/good.jpg');
        self::assertFileDoesNotExist($this->galleryDir() . '/bright.jpg');
    }

    public function testAutoSortGalleryReturnsZerosWhenGalleryMissing(): void
    {
        $this->removeDir($this->galleryDir());

        $result = $this->service->autoSortGallery(self::SLUG);

        self::assertSame(0, $result['scanned']);
        self::assertSame(0, $result['moved']);
        self::assertSame([], $result['by_reason']);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function testMoveToTrashRejectsInvalidSlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->moveToTrash('slug-inexistant', 'photo.jpg', 'manual');
    }

    public function testMoveToTrashRejectsTraversalFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->moveToTrash(self::SLUG, '../evil.jpg', 'manual');
    }

    public function testListTrashedRejectsInvalidSlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->listTrashed('not-a-slug');
    }

    public function testGetAllowedSlugsMirrorsGalleryConfig(): void
    {
        self::assertSame(GalleryConfig::getAllowedSlugs(), GalleryTrashService::getAllowedSlugs());
    }

    /**
     * Suppression récursive d'un répertoire temporaire de test.
     */
    private function removeDir(?string $dir): void
    {
        if ($dir === null || !is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
