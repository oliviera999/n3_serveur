<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GalleryConfig;
use App\Config\Paths;

/**
 * Emplacements des fichiers dérivés de la normalisation galerie (`uploads/{slug}/.normalized/`).
 *
 * Pour une photo `foo.jpg` :
 * - `foo.jpg`            copie normalisée (servie à la place de l'original) ;
 * - `foo.jpg.v{N}.json`  méta de l'algorithme courant (statut, paramètres appliqués).
 *                        Une copie SANS méta de la version courante est périmée
 *                        (algorithme antérieur) : elle n'est ni servie ni promue ;
 * - `foo.jpg.done`       l'original a été remplacé par sa copie (purge) : ne plus traiter.
 *
 * Sans dépendance (pas d'injection) : utilisé à la fois par `GalleryNormalizeService`
 * et `GalleryTrashService`, qui doit nettoyer ces fichiers quand une photo part en corbeille.
 */
final class GalleryNormalizedStore
{
    public const DIRNAME = '.normalized';

    /** Version de l'algorithme : l'incrémenter invalide toutes les copies existantes. */
    public const ALGO_VERSION = 2;

    private const TMP_INFIX = '.tmp.';

    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? Paths::getProjectRoot();
    }

    public function galleryDir(string $slug): string
    {
        return $this->baseDir . '/' . rtrim(GalleryConfig::resolveUploadDir($slug), '/');
    }

    public function dir(string $slug): string
    {
        return $this->galleryDir($slug) . '/' . self::DIRNAME;
    }

    public function originalPath(string $slug, string $filename): string
    {
        return $this->galleryDir($slug) . '/' . $filename;
    }

    public function copyPath(string $slug, string $filename): string
    {
        return $this->dir($slug) . '/' . $filename;
    }

    public function metaPath(string $slug, string $filename): string
    {
        return $this->dir($slug) . '/' . $filename . '.v' . self::ALGO_VERSION . '.json';
    }

    public function donePath(string $slug, string $filename): string
    {
        return $this->dir($slug) . '/' . $filename . '.done';
    }

    public function tmpPath(string $finalPath): string
    {
        return $finalPath . self::TMP_INFIX . getmypid() . '.' . bin2hex(random_bytes(3));
    }

    public function isPurged(string $slug, string $filename): bool
    {
        return is_file($this->donePath($slug, $filename));
    }

    public function hasCurrentMeta(string $slug, string $filename): bool
    {
        return is_file($this->metaPath($slug, $filename));
    }

    /**
     * Copie normalisée valide (produite par l'algorithme courant) ?
     */
    public function hasCurrentCopy(string $slug, string $filename): bool
    {
        return $this->hasCurrentMeta($slug, $filename) && is_file($this->copyPath($slug, $filename));
    }

    public function ensureDir(string $slug): bool
    {
        $dir = $this->dir($slug);

        return is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir);
    }

    /**
     * Supprime la copie et les métas (toutes versions) d'une photo ; conserve `.done`.
     */
    public function removeDerived(string $slug, string $filename): void
    {
        $dir = $this->dir($slug);
        if (!is_dir($dir)) {
            return;
        }
        @unlink($dir . '/' . $filename);
        // Noms de fichiers galerie : [a-zA-Z0-9_.-] uniquement → aucun métacaractère glob.
        foreach (glob($dir . '/' . $filename . '.v*.json') ?: [] as $meta) {
            @unlink($meta);
        }
    }

    /**
     * Supprime tout ce qui concerne une photo dans `.normalized/` (y compris `.done`).
     */
    public function removeAll(string $slug, string $filename): void
    {
        $this->removeDerived($slug, $filename);
        $done = $this->donePath($slug, $filename);
        if (is_file($done)) {
            @unlink($done);
        }
    }

    /**
     * Supprime les fichiers temporaires abandonnés (process tué pendant l'écriture).
     *
     * @return int nombre de fichiers supprimés
     */
    public function cleanupStaleTmp(string $slug, int $olderThanSeconds): int
    {
        $dir = $this->dir($slug);
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        $threshold = time() - $olderThanSeconds;
        foreach (glob($dir . '/*' . self::TMP_INFIX . '*') ?: [] as $tmp) {
            $mtime = @filemtime($tmp);
            if ($mtime !== false && $mtime < $threshold && @unlink($tmp)) {
                $removed++;
            }
        }

        return $removed;
    }
}
