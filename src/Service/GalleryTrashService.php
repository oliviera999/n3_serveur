<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GalleryConfig;
use App\Config\Paths;

/**
 * Gestion de la corbeille photo pour les galeries ESP32-CAM.
 * Stockage : uploads/trash/{slug}/ avec métadonnées JSON.
 */
class GalleryTrashService
{
    private const FILENAME_PATTERN = '/^[a-zA-Z0-9_.-]+\.(jpg|jpeg)$/';
    private const METADATA_FILE = '_metadata.json';

    private const BRIGHTNESS_TOO_BRIGHT = 235;
    private const BRIGHTNESS_TOO_DARK = 20;
    private const SAMPLE_PIXELS = 2000;

    private string $baseDir;

    public function __construct(
        private LogService $logger,
    ) {
        $this->baseDir = Paths::getProjectRoot();
    }

    // ------------------------------------------------------------------
    // Analyse d'image (luminosité + corruption)
    // ------------------------------------------------------------------

    /**
     * @return array{quality: string, reason: string|null, brightness: float|null}
     *   quality = 'ok' | 'too_bright' | 'too_dark' | 'corrupted'
     */
    public function analyzeImage(string $filepath): array
    {
        if (!is_file($filepath) || !is_readable($filepath)) {
            return ['quality' => 'corrupted', 'reason' => 'Fichier introuvable ou illisible', 'brightness' => null];
        }

        $img = @imagecreatefromjpeg($filepath);
        if ($img === false) {
            return ['quality' => 'corrupted', 'reason' => 'Impossible de décoder le JPEG', 'brightness' => null];
        }

        $width = imagesx($img);
        $height = imagesy($img);

        if ($width < 10 || $height < 10) {
            imagedestroy($img);
            return ['quality' => 'corrupted', 'reason' => 'Image trop petite (' . $width . 'x' . $height . ')', 'brightness' => null];
        }

        $totalBrightness = 0.0;
        $samples = min(self::SAMPLE_PIXELS, $width * $height);
        for ($i = 0; $i < $samples; $i++) {
            $x = mt_rand(0, $width - 1);
            $y = mt_rand(0, $height - 1);
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $totalBrightness += ($r * 0.299 + $g * 0.587 + $b * 0.114);
        }
        imagedestroy($img);

        $avgBrightness = $totalBrightness / $samples;

        if ($avgBrightness >= self::BRIGHTNESS_TOO_BRIGHT) {
            return ['quality' => 'too_bright', 'reason' => 'Image surexposée (luminosité ' . round($avgBrightness) . '/255)', 'brightness' => round($avgBrightness, 1)];
        }
        if ($avgBrightness <= self::BRIGHTNESS_TOO_DARK) {
            return ['quality' => 'too_dark', 'reason' => 'Image sous-exposée (luminosité ' . round($avgBrightness) . '/255)', 'brightness' => round($avgBrightness, 1)];
        }

        return ['quality' => 'ok', 'reason' => null, 'brightness' => round($avgBrightness, 1)];
    }

    // ------------------------------------------------------------------
    // Déplacement vers la corbeille
    // ------------------------------------------------------------------

    public function moveToTrash(string $slug, string $filename, string $reason = 'manual'): bool
    {
        $this->validateSlug($slug);
        $this->validateFilename($filename);

        $sourceDir = $this->getGalleryDir($slug);
        $sourcePath = $sourceDir . '/' . $filename;
        if (!is_file($sourcePath)) {
            return false;
        }

        $trashDir = $this->getTrashDir($slug);
        if (!is_dir($trashDir)) {
            mkdir($trashDir, 0755, true);
        }

        $destPath = $trashDir . '/' . $filename;
        if (!rename($sourcePath, $destPath)) {
            $this->logger->error("GalleryTrash: impossible de déplacer {$filename} vers la corbeille [{$slug}]");
            return false;
        }

        $this->setMetadata($slug, $filename, [
            'original_slug' => $slug,
            'trashed_at' => date('c'),
            'reason' => $reason,
        ]);

        $this->logger->info("GalleryTrash: {$filename} déplacé en corbeille [{$slug}] (motif: {$reason})");
        return true;
    }

    // ------------------------------------------------------------------
    // Restauration
    // ------------------------------------------------------------------

    public function restore(string $slug, string $filename): bool
    {
        $this->validateSlug($slug);
        $this->validateFilename($filename);

        $trashDir = $this->getTrashDir($slug);
        $trashPath = $trashDir . '/' . $filename;
        if (!is_file($trashPath)) {
            return false;
        }

        $meta = $this->getMetadata($slug, $filename);
        $originalSlug = $meta['original_slug'] ?? $slug;
        $this->validateSlug($originalSlug);

        $galleryDir = $this->getGalleryDir($originalSlug);
        if (!is_dir($galleryDir)) {
            mkdir($galleryDir, 0755, true);
        }

        $destPath = $galleryDir . '/' . $filename;
        if (!rename($trashPath, $destPath)) {
            $this->logger->error("GalleryTrash: impossible de restaurer {$filename} depuis la corbeille [{$slug}]");
            return false;
        }

        $this->removeMetadata($slug, $filename);
        $this->logger->info("GalleryTrash: {$filename} restauré dans la galerie [{$originalSlug}]");
        return true;
    }

    // ------------------------------------------------------------------
    // Suppression définitive
    // ------------------------------------------------------------------

    public function purge(string $slug, string $filename): bool
    {
        $this->validateSlug($slug);
        $this->validateFilename($filename);

        $trashDir = $this->getTrashDir($slug);
        $path = $trashDir . '/' . $filename;
        if (!is_file($path)) {
            return false;
        }

        if (!unlink($path)) {
            $this->logger->error("GalleryTrash: impossible de supprimer {$filename} [{$slug}]");
            return false;
        }

        $this->removeMetadata($slug, $filename);
        $this->logger->info("GalleryTrash: {$filename} supprimé définitivement [{$slug}]");
        return true;
    }

    /** @return array{deleted: int, failed: int} */
    public function purgeSelected(string $slug, array $filenames): array
    {
        $deleted = 0;
        $failed = 0;
        foreach ($filenames as $f) {
            if ($this->purge($slug, $f)) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /** @return array{deleted: int, failed: int} */
    public function purgeAll(string $slug): array
    {
        $files = $this->listTrashed($slug);
        $filenames = array_column($files, 'filename');
        return $this->purgeSelected($slug, $filenames);
    }

    // ------------------------------------------------------------------
    // Liste des fichiers en corbeille
    // ------------------------------------------------------------------

    /** @return list<array{filename: string, trashed_at: string|null, reason: string|null, original_slug: string}> */
    public function listTrashed(string $slug): array
    {
        $this->validateSlug($slug);
        $trashDir = $this->getTrashDir($slug);
        if (!is_dir($trashDir)) {
            return [];
        }

        $allMeta = $this->loadAllMetadata($slug);
        $files = [];
        foreach (scandir($trashDir) as $name) {
            if ($name === '.' || $name === '..' || $name === self::METADATA_FILE) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg'], true) || !is_file($trashDir . '/' . $name)) {
                continue;
            }
            $meta = $allMeta[$name] ?? [];
            $files[] = [
                'filename' => $name,
                'trashed_at' => $meta['trashed_at'] ?? null,
                'reason' => $meta['reason'] ?? 'unknown',
                'original_slug' => $meta['original_slug'] ?? $slug,
            ];
        }

        usort($files, fn(array $a, array $b) => strcmp($b['filename'], $a['filename']));
        return $files;
    }

    /** @return array<string, int> */
    public function countAllTrashed(): array
    {
        $counts = [];
        foreach (GalleryConfig::getAllowedSlugs() as $slug) {
            $counts[$slug] = count($this->listTrashed($slug));
        }
        return $counts;
    }

    public function getTrashImagePath(string $slug, string $filename): ?string
    {
        $this->validateSlug($slug);
        $this->validateFilename($filename);

        $path = $this->getTrashDir($slug) . '/' . $filename;
        return (is_file($path) && is_readable($path)) ? $path : null;
    }

    // ------------------------------------------------------------------
    // Chemins
    // ------------------------------------------------------------------

    private function getGalleryDir(string $slug): string
    {
        return $this->baseDir . '/' . rtrim(GalleryConfig::resolveUploadDir($slug), '/');
    }

    private function getTrashDir(string $slug): string
    {
        return $this->baseDir . '/uploads/trash/' . $slug;
    }

    // ------------------------------------------------------------------
    // Métadonnées JSON
    // ------------------------------------------------------------------

    private function loadAllMetadata(string $slug): array
    {
        $path = $this->getTrashDir($slug) . '/' . self::METADATA_FILE;
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function saveAllMetadata(string $slug, array $data): void
    {
        $dir = $this->getTrashDir($slug);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $dir . '/' . self::METADATA_FILE,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function setMetadata(string $slug, string $filename, array $meta): void
    {
        $all = $this->loadAllMetadata($slug);
        $all[$filename] = $meta;
        $this->saveAllMetadata($slug, $all);
    }

    private function removeMetadata(string $slug, string $filename): void
    {
        $all = $this->loadAllMetadata($slug);
        unset($all[$filename]);
        $this->saveAllMetadata($slug, $all);
    }

    private function getMetadata(string $slug, string $filename): array
    {
        $all = $this->loadAllMetadata($slug);
        return $all[$filename] ?? [];
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    private function validateSlug(string $slug): void
    {
        if (!GalleryConfig::isValidSlug($slug)) {
            throw new \InvalidArgumentException("Slug de galerie invalide : {$slug}");
        }
    }

    private function validateFilename(string $filename): void
    {
        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            throw new \InvalidArgumentException("Nom de fichier invalide : {$filename}");
        }
    }

    /** @return list<string> */
    public static function getAllowedSlugs(): array
    {
        return GalleryConfig::getAllowedSlugs();
    }
}
