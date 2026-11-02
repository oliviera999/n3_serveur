<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Date de capture d'une photo galerie, déduite du nom de fichier.
 *
 * Formats reconnus (cf. `GalleryUploadController::buildFilename`) :
 * - `Y-m-d_H-i-s_<rand>.jpg` (legacy)
 * - `<seq>_Y-m-d_H-i-s_<rand>.jpg` (N-first, compteur firmware)
 *
 * Repli sur `filemtime()` quand le nom ne contient pas de date (anciens fichiers).
 * Source unique partagée par la consultation (`GalleryViewController`) et la
 * normalisation (`GalleryNormalizeService`, ordre des voisins pour le lissage).
 */
final class GalleryTimestamp
{
    private const PATTERN = '/^(?:\d+_)?(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})_/';

    /**
     * Date embarquée dans le nom, ou null si absente / invalide.
     */
    public static function fromFilename(string $filename): ?\DateTimeImmutable
    {
        $match = [];
        if (preg_match(self::PATTERN, $filename, $match) !== 1) {
            return null;
        }

        try {
            return new \DateTimeImmutable($match[1] . ' ' . $match[2] . ':' . $match[3] . ':' . $match[4]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Date embarquée dans le nom, sinon date de modification du fichier (sinon maintenant).
     */
    public static function fromFile(string $fullPath, string $filename): \DateTimeImmutable
    {
        $fromName = self::fromFilename($filename);
        if ($fromName !== null) {
            return $fromName;
        }

        $mtime = @filemtime($fullPath) ?: time();

        return (new \DateTimeImmutable())->setTimestamp($mtime);
    }
}
