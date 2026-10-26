<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GalleryConfig;
use App\Config\Paths;

/**
 * Normalisation douce des photos galerie (luminosité, balance des blancs, contraste)
 * pour le timelapse — sans dénaturer l'image.
 *
 * Stockage : original intact dans uploads/{slug}/ ; copie dans uploads/{slug}/.normalized/.
 * Après purge des originaux : la copie est promue et un marqueur .done empêche le retraitement.
 *
 * Inspiration : balance gray-world classique + auto-levels doux (mélange avec l'original).
 */
class GalleryNormalizeService
{
    private const FILENAME_PATTERN = '/^[a-zA-Z0-9_.-]+\.(jpg|jpeg)$/i';
    private const NORM_DIRNAME = '.normalized';
    private const SAMPLE_PIXELS = 2500;
    private const DEFAULT_STRENGTH = 0.35;
    private const DEFAULT_JPEG_QUALITY = 90;
    private const DEFAULT_MAX_PER_RUN = 20;
    private const WB_GAIN_MIN = 0.88;
    private const WB_GAIN_MAX = 1.12;
    private const BRIGHTNESS_TARGET = 120.0;
    private const BRIGHTNESS_DELTA_MAX = 0.10;
    private const CONTRAST_PERCENTILE_LOW = 0.03;
    private const CONTRAST_PERCENTILE_HIGH = 0.97;

    private string $baseDir;

    public function __construct(
        private LogService $logger,
        private ?OperationalSettingsService $operationalSettings = null,
    ) {
        $this->baseDir = Paths::getProjectRoot();
    }

    /**
     * Chemin à servir pour un fichier galerie (copie normalisée si présente).
     */
    public function resolveDisplayPath(string $slug, string $filename): ?string
    {
        $this->validateSlug($slug);
        $this->validateFilename($filename);

        $normPath = $this->getNormalizedPath($slug, $filename);
        if (is_file($normPath) && is_readable($normPath)) {
            return $normPath;
        }

        $original = $this->getGalleryDir($slug) . '/' . $filename;
        if (is_file($original) && is_readable($original)) {
            return $original;
        }

        return null;
    }

    public function isAlreadyProcessed(string $slug, string $filename): bool
    {
        $normPath = $this->getNormalizedPath($slug, $filename);
        if (is_file($normPath)) {
            return true;
        }

        return is_file($this->getDoneMarkerPath($slug, $filename));
    }

    /**
     * Traite jusqu'à $max images en attente sur toutes les galeries (plus anciennes d'abord).
     *
     * @return array{pending:int, processed:int, skipped:int, failed:int, by_slug: array<string,int>}
     */
    public function processBatch(?int $max = null): array
    {
        $max = $max ?? $this->resolveMaxPerRun();
        $max = max(1, min(200, $max));
        $strength = $this->resolveStrength();
        $quality = $this->resolveJpegQuality();

        $candidates = $this->listPendingCandidates();
        $pending = count($candidates);

        $result = [
            'pending' => $pending,
            'processed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'by_slug' => [],
        ];

        $batch = array_slice($candidates, 0, $max);
        foreach ($batch as $item) {
            $slug = $item['slug'];
            $filename = $item['filename'];
            try {
                if ($this->isAlreadyProcessed($slug, $filename)) {
                    $result['skipped']++;
                    continue;
                }
                if ($this->normalizeFile($slug, $filename, $strength, $quality)) {
                    $result['processed']++;
                    $result['by_slug'][$slug] = ($result['by_slug'][$slug] ?? 0) + 1;
                } else {
                    $result['failed']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $this->logger->warning(
                    "GalleryNormalize: échec {$filename} [{$slug}] — " . $e->getMessage()
                );
            }
        }

        $this->logger->info(sprintf(
            'GalleryNormalize: batch terminé — pending=%d processed=%d skipped=%d failed=%d',
            $pending,
            $result['processed'],
            $result['skipped'],
            $result['failed']
        ));

        return $result;
    }

    /**
     * Purge les originaux qui ont une copie normalisée : promeut la copie, pose .done.
     *
     * @return array{purged:int, skipped:int, failed:int, by_slug: array<string,int>}
     */
    public function purgeNormalizedOriginals(?string $onlySlug = null): array
    {
        $result = [
            'purged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'by_slug' => [],
        ];

        $slugs = $onlySlug !== null
            ? [$onlySlug]
            : GalleryConfig::getAllowedSlugs();

        foreach ($slugs as $slug) {
            if (!GalleryConfig::isValidSlug($slug)) {
                continue;
            }
            $galleryDir = $this->getGalleryDir($slug);
            $normDir = $this->getNormalizedDir($slug);
            if (!is_dir($galleryDir) || !is_dir($normDir)) {
                continue;
            }

            foreach (scandir($normDir) ?: [] as $name) {
                if ($name === '.' || $name === '..' || str_ends_with($name, '.done')) {
                    continue;
                }
                if (!preg_match(self::FILENAME_PATTERN, $name)) {
                    continue;
                }

                $normPath = $normDir . '/' . $name;
                $originalPath = $galleryDir . '/' . $name;
                if (!is_file($normPath)) {
                    continue;
                }
                if (!is_file($originalPath)) {
                    // Copie orpheline : poser .done et laisser la copie (ou la promouvoir si besoin)
                    if (@rename($normPath, $originalPath)) {
                        $this->touchDoneMarker($slug, $name);
                        $result['purged']++;
                        $result['by_slug'][$slug] = ($result['by_slug'][$slug] ?? 0) + 1;
                    } else {
                        $result['skipped']++;
                    }
                    continue;
                }

                if (!@unlink($originalPath)) {
                    $result['failed']++;
                    $this->logger->error("GalleryNormalize: impossible de supprimer l'original {$name} [{$slug}]");
                    continue;
                }
                if (!@rename($normPath, $originalPath)) {
                    $result['failed']++;
                    $this->logger->error("GalleryNormalize: impossible de promouvoir {$name} [{$slug}]");
                    continue;
                }
                $this->touchDoneMarker($slug, $name);
                $result['purged']++;
                $result['by_slug'][$slug] = ($result['by_slug'][$slug] ?? 0) + 1;
            }
        }

        $this->logger->info(sprintf(
            'GalleryNormalize: purge originaux — purged=%d skipped=%d failed=%d',
            $result['purged'],
            $result['skipped'],
            $result['failed']
        ));

        return $result;
    }

    /**
     * Compte les photos en attente de normalisation.
     */
    public function countPending(): int
    {
        return count($this->listPendingCandidates());
    }

    public function normalizeFile(
        string $slug,
        string $filename,
        ?float $strength = null,
        ?int $quality = null,
    ): bool {
        $this->validateSlug($slug);
        $this->validateFilename($filename);

        if ($this->isAlreadyProcessed($slug, $filename)) {
            return false;
        }

        $source = $this->getGalleryDir($slug) . '/' . $filename;
        if (!is_file($source) || !is_readable($source)) {
            return false;
        }

        $strength ??= $this->resolveStrength();
        $quality ??= $this->resolveJpegQuality();
        $strength = max(0.0, min(1.0, $strength));
        $quality = max(60, min(95, $quality));

        $img = @imagecreatefromjpeg($source);
        if ($img === false) {
            $this->logger->warning("GalleryNormalize: JPEG illisible {$filename} [{$slug}]");
            return false;
        }

        $width = imagesx($img);
        $height = imagesy($img);
        if ($width < 10 || $height < 10) {
            imagedestroy($img);
            return false;
        }

        $stats = $this->sampleStats($img, $width, $height);
        $gains = $this->computeWbGains($stats['mean_r'], $stats['mean_g'], $stats['mean_b']);
        $brightnessDelta = $this->computeBrightnessDelta($stats['mean_luma']);
        $stretch = $this->computeContrastStretch($stats['luma_hist'], $strength);

        $out = imagecreatetruecolor($width, $height);
        if ($out === false) {
            imagedestroy($img);
            return false;
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $pr = $this->clampByte($r * $gains['r'] + $brightnessDelta);
                $pg = $this->clampByte($g * $gains['g'] + $brightnessDelta);
                $pb = $this->clampByte($b * $gains['b'] + $brightnessDelta);

                if ($stretch['apply']) {
                    $pr = $this->applyStretch($pr, $stretch);
                    $pg = $this->applyStretch($pg, $stretch);
                    $pb = $this->applyStretch($pb, $stretch);
                }

                // Mélange avec l'original pour ne pas dénaturer
                $fr = (int) round($r * (1.0 - $strength) + $pr * $strength);
                $fg = (int) round($g * (1.0 - $strength) + $pg * $strength);
                $fb = (int) round($b * (1.0 - $strength) + $pb * $strength);

                imagesetpixel($out, $x, $y, ($fr << 16) | ($fg << 8) | $fb);
            }
        }

        imagedestroy($img);

        $normDir = $this->getNormalizedDir($slug);
        if (!is_dir($normDir) && !@mkdir($normDir, 0755, true)) {
            imagedestroy($out);
            $this->logger->error("GalleryNormalize: impossible de créer {$normDir}");
            return false;
        }

        $dest = $this->getNormalizedPath($slug, $filename);
        $tmp = $dest . '.tmp.' . getmypid();
        $ok = imagejpeg($out, $tmp, $quality);
        imagedestroy($out);

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            $this->logger->error("GalleryNormalize: rename atomique échoué pour {$filename} [{$slug}]");
            return false;
        }

        return true;
    }

    /**
     * @return list<array{slug: string, filename: string, mtime: int}>
     */
    private function listPendingCandidates(): array
    {
        $candidates = [];
        foreach (GalleryConfig::getAllowedSlugs() as $slug) {
            $galleryDir = $this->getGalleryDir($slug);
            if (!is_dir($galleryDir)) {
                continue;
            }
            foreach (scandir($galleryDir) ?: [] as $name) {
                if ($name === '.' || $name === '..' || $name === self::NORM_DIRNAME) {
                    continue;
                }
                $path = $galleryDir . '/' . $name;
                if (!is_file($path) || !preg_match(self::FILENAME_PATTERN, $name)) {
                    continue;
                }
                if ($this->isAlreadyProcessed($slug, $name)) {
                    continue;
                }
                $candidates[] = [
                    'slug' => $slug,
                    'filename' => $name,
                    'mtime' => (int) (@filemtime($path) ?: 0),
                ];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);

        return $candidates;
    }

    /**
     * @return array{
     *   mean_r: float,
     *   mean_g: float,
     *   mean_b: float,
     *   mean_luma: float,
     *   luma_hist: array<int, int>
     * }
     */
    private function sampleStats(\GdImage $img, int $width, int $height): array
    {
        $sumR = 0.0;
        $sumG = 0.0;
        $sumB = 0.0;
        $sumLuma = 0.0;
        $hist = array_fill(0, 256, 0);
        $samples = min(self::SAMPLE_PIXELS, $width * $height);

        for ($i = 0; $i < $samples; $i++) {
            $x = mt_rand(0, $width - 1);
            $y = mt_rand(0, $height - 1);
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $luma = (int) round($r * 0.299 + $g * 0.587 + $b * 0.114);
            $sumR += $r;
            $sumG += $g;
            $sumB += $b;
            $sumLuma += $luma;
            $hist[$luma]++;
        }

        return [
            'mean_r' => $sumR / $samples,
            'mean_g' => $sumG / $samples,
            'mean_b' => $sumB / $samples,
            'mean_luma' => $sumLuma / $samples,
            'luma_hist' => $hist,
        ];
    }

    /**
     * @return array{r: float, g: float, b: float}
     */
    private function computeWbGains(float $meanR, float $meanG, float $meanB): array
    {
        $gray = ($meanR + $meanG + $meanB) / 3.0;
        if ($gray < 1.0) {
            return ['r' => 1.0, 'g' => 1.0, 'b' => 1.0];
        }

        return [
            'r' => $this->clampGain($gray / max($meanR, 1.0)),
            'g' => $this->clampGain($gray / max($meanG, 1.0)),
            'b' => $this->clampGain($gray / max($meanB, 1.0)),
        ];
    }

    private function computeBrightnessDelta(float $meanLuma): float
    {
        $deltaNorm = (self::BRIGHTNESS_TARGET - $meanLuma) / 255.0;
        $deltaNorm = max(-self::BRIGHTNESS_DELTA_MAX, min(self::BRIGHTNESS_DELTA_MAX, $deltaNorm));

        return $deltaNorm * 255.0;
    }

    /**
     * @param array<int, int> $hist
     * @return array{apply: bool, low: float, high: float, scale: float}
     */
    private function computeContrastStretch(array $hist, float $strength): array
    {
        $total = array_sum($hist);
        if ($total < 10 || $strength <= 0.0) {
            return ['apply' => false, 'low' => 0.0, 'high' => 255.0, 'scale' => 1.0];
        }

        $lowCount = (int) floor($total * self::CONTRAST_PERCENTILE_LOW);
        $highCount = (int) ceil($total * self::CONTRAST_PERCENTILE_HIGH);
        $cum = 0;
        $low = 0;
        $high = 255;
        for ($i = 0; $i < 256; $i++) {
            $cum += $hist[$i];
            if ($cum >= $lowCount) {
                $low = $i;
                break;
            }
        }
        $cum = 0;
        for ($i = 0; $i < 256; $i++) {
            $cum += $hist[$i];
            if ($cum >= $highCount) {
                $high = $i;
                break;
            }
        }

        if ($high <= $low + 8) {
            return ['apply' => false, 'low' => 0.0, 'high' => 255.0, 'scale' => 1.0];
        }

        return [
            'apply' => true,
            'low' => (float) $low,
            'high' => (float) $high,
            'scale' => 255.0 / ($high - $low),
        ];
    }

    /**
     * @param array{apply: bool, low: float, high: float, scale: float} $stretch
     */
    private function applyStretch(float $value, array $stretch): float
    {
        return ($value - $stretch['low']) * $stretch['scale'];
    }

    private function clampGain(float $gain): float
    {
        return max(self::WB_GAIN_MIN, min(self::WB_GAIN_MAX, $gain));
    }

    private function clampByte(float $v): float
    {
        return max(0.0, min(255.0, $v));
    }

    private function resolveStrength(): float
    {
        return $this->operationalSettings?->float('GALLERY_NORMALIZE_STRENGTH', self::DEFAULT_STRENGTH)
            ?? (float) ($_ENV['GALLERY_NORMALIZE_STRENGTH'] ?? self::DEFAULT_STRENGTH);
    }

    private function resolveJpegQuality(): int
    {
        return $this->operationalSettings?->int('GALLERY_NORMALIZE_JPEG_QUALITY', self::DEFAULT_JPEG_QUALITY)
            ?? (int) ($_ENV['GALLERY_NORMALIZE_JPEG_QUALITY'] ?? self::DEFAULT_JPEG_QUALITY);
    }

    private function resolveMaxPerRun(): int
    {
        return $this->operationalSettings?->int('GALLERY_NORMALIZE_MAX_PER_RUN', self::DEFAULT_MAX_PER_RUN)
            ?? (int) ($_ENV['GALLERY_NORMALIZE_MAX_PER_RUN'] ?? self::DEFAULT_MAX_PER_RUN);
    }

    private function getGalleryDir(string $slug): string
    {
        return $this->baseDir . '/' . GalleryConfig::resolveUploadDir($slug);
    }

    private function getNormalizedDir(string $slug): string
    {
        return $this->getGalleryDir($slug) . '/' . self::NORM_DIRNAME;
    }

    private function getNormalizedPath(string $slug, string $filename): string
    {
        return $this->getNormalizedDir($slug) . '/' . $filename;
    }

    private function getDoneMarkerPath(string $slug, string $filename): string
    {
        return $this->getNormalizedDir($slug) . '/' . $filename . '.done';
    }

    private function touchDoneMarker(string $slug, string $filename): void
    {
        $dir = $this->getNormalizedDir($slug);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->getDoneMarkerPath($slug, $filename), date('c'));
    }

    private function validateSlug(string $slug): void
    {
        if (!GalleryConfig::isValidSlug($slug)) {
            throw new \InvalidArgumentException("Slug galerie invalide: {$slug}");
        }
    }

    private function validateFilename(string $filename): void
    {
        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            throw new \InvalidArgumentException("Nom de fichier invalide: {$filename}");
        }
    }
}
