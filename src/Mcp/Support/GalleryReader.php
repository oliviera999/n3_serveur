<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Config\GalleryConfig;
use App\Service\GalleryNormalizedStore;
use App\Util\GalleryTimestamp;

/**
 * Lecture des galeries photo pour le connecteur MCP (aucune écriture, aucune suppression).
 *
 * Même résolution de dossier que les pages galerie ({@see GalleryConfig::resolveUploadDir()}),
 * même tri (date de capture issue du nom, sinon mtime), copie normalisée servie si elle existe.
 * Les noms de fichiers sont filtrés par motif strict (pas de traversée de chemin).
 */
final class GalleryReader
{
    public const MAX_IMAGE_EDGE = 800;
    public const MAX_IMAGE_BYTES = 900_000;
    private const FILENAME_PATTERN = '/^[a-zA-Z0-9_.-]+\.(jpg|jpeg)$/i';

    private GalleryNormalizedStore $store;

    public function __construct(?string $projectRoot = null)
    {
        $this->store = new GalleryNormalizedStore($projectRoot);
    }

    /**
     * Photos de la galerie, de la plus récente à la plus ancienne.
     *
     * @return list<array{name: string, ts: int}>
     */
    public function entries(string $slug): array
    {
        if (!GalleryConfig::isValidSlug($slug)) {
            throw new \InvalidArgumentException('Galerie inconnue : ' . $slug);
        }
        $dir = $this->store->galleryDir($slug);
        if (!is_dir($dir)) {
            return [];
        }
        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match(self::FILENAME_PATTERN, $name) !== 1 || !is_file($dir . '/' . $name)) {
                continue;
            }
            $entries[] = ['name' => $name, 'ts' => GalleryTimestamp::fromFile($dir . '/' . $name, $name)->getTimestamp()];
        }
        usort($entries, static fn (array $a, array $b): int => [$b['ts'], $b['name']] <=> [$a['ts'], $a['name']]);

        return $entries;
    }

    /**
     * Chemin servi pour une photo (copie normalisée courante sinon original), ou null.
     */
    public function displayPath(string $slug, string $filename): ?string
    {
        if (!GalleryConfig::isValidSlug($slug) || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            return null;
        }
        $original = $this->store->originalPath($slug, $filename);
        if (!is_file($original)) {
            return null;
        }
        if ($this->store->hasCurrentCopy($slug, $filename) && is_readable($this->store->copyPath($slug, $filename))) {
            return $this->store->copyPath($slug, $filename);
        }

        return $original;
    }

    public function isNormalized(string $slug, string $filename): bool
    {
        return $this->store->hasCurrentCopy($slug, $filename);
    }

    /**
     * JPEG redimensionné (plus grand côté ≤ $maxEdge, < MAX_IMAGE_BYTES) encodé en base64.
     *
     * @return array{data: string, mime: string, width: int, height: int, bytes: int, resized: bool}|null
     */
    public function encodeForModel(string $path, int $maxEdge = self::MAX_IMAGE_EDGE): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $size = @getimagesizefromstring($raw);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;

        if (!function_exists('imagecreatefromstring')) {
            // Sans GD : original uniquement s'il est déjà assez léger.
            return strlen($raw) <= self::MAX_IMAGE_BYTES
                ? ['data' => base64_encode($raw), 'mime' => 'image/jpeg', 'width' => $width, 'height' => $height, 'bytes' => strlen($raw), 'resized' => false]
                : null;
        }

        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            return null;
        }
        $srcW = imagesx($image);
        $srcH = imagesy($image);
        $ratio = min(1.0, $maxEdge / max(1, max($srcW, $srcH)));
        $dstW = max(1, (int) round($srcW * $ratio));
        $dstH = max(1, (int) round($srcH * $ratio));
        $target = $image;
        if ($ratio < 1.0) {
            $resized = imagecreatetruecolor($dstW, $dstH);
            if ($resized === false) {
                return null;
            }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            $target = $resized;
        }

        $jpeg = '';
        foreach ([82, 70, 55, 40] as $quality) {
            ob_start();
            imagejpeg($target, null, $quality);
            $jpeg = (string) ob_get_clean();
            if (strlen($jpeg) <= self::MAX_IMAGE_BYTES) {
                break;
            }
        }
        if ($jpeg === '' || strlen($jpeg) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return [
            'data' => base64_encode($jpeg),
            'mime' => 'image/jpeg',
            'width' => $dstW,
            'height' => $dstH,
            'bytes' => strlen($jpeg),
            'resized' => $ratio < 1.0,
        ];
    }
}
