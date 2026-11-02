<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GalleryConfig;
use App\Util\GalleryTimestamp;

/**
 * Normalisation douce des photos galerie (balance des blancs, luminosité, contraste)
 * pour le timelapse — sans dénaturer l'image. Algorithme v2 (cf. `GalleryNormalizedStore::ALGO_VERSION`).
 *
 * Stockage : original intact dans `uploads/{slug}/` ; copie + méta dans `uploads/{slug}/.normalized/`.
 * Les copies produites par une version antérieure de l'algorithme (sans méta courante) sont
 * considérées périmées : jamais servies ni promues, et recalculées par le batch.
 *
 * Pipeline par photo :
 * 1. Statistiques sur une grille déterministe 50×50 (reproductible d'un passage à l'autre).
 * 2. Image très sombre (nuit) → non traitée (méta `skipped_dark`, l'original reste servi) ;
 *    elle est aussi exclue des fenêtres de lissage de ses voisines.
 * 3. Balance des blancs « gray-world robuste » : moyennes calculées uniquement sur les pixels
 *    quasi neutres (faible saturation, ni bouchés ni brûlés) ; si trop peu de pixels neutres
 *    (scène dominée par la végétation), pas de correction. Gains bornés à ±12 %.
 *    Inspiration : Huo, Chang, Wang & Wei, « Robust automatic white balance algorithm using
 *    gray color points in images », IEEE Trans. Consumer Electronics 52(2), 2006
 *    (idée reprise et simplifiée : critère de saturation HSV au lieu de YUV).
 * 4. Luminosité : décalage borné à ±10 % vers une cible.
 * 5. Contraste : auto-levels PARTIEL, calculé sur l'histogramme APRÈS gains + deflicker + luminosité,
 *    avec un facteur d'étirement plafonné (`STRETCH_SCALE_MAX`) — une image peu contrastée
 *    ne peut plus être amplifiée ×15.
 * 6. Lissage temporel (anti-scintillement) : les statistiques (luminance, pixels neutres,
 *    niveaux) sont moyennées sur une fenêtre de 5 photos voisines dans le temps (poids
 *    triangulaires, écart ≤ 2 h) et chaque photo reçoit un gain d'exposition (±15 %) qui la
 *    ramène vers la luminance moyenne de la fenêtre. Principe repris du filtre `deflicker` de
 *    FFmpeg (moyenne arithmétique glissante, taille 5 par défaut —
 *    https://ffmpeg.org/ffmpeg-filters.html#deflicker), étendu à la balance des blancs et aux
 *    niveaux pour que la correction elle-même ne varie pas d'une photo à l'autre.
 * 7. Application par tables de correspondance 3×256 (chaque canal est transformé
 *    indépendamment) : toutes les valeurs sont bornées à [0, 255] → plus aucun débordement
 *    sur le canal voisin / l'alpha. Mélange final avec l'original selon la force.
 * 8. Écriture atomique (tmp + rename) ; les segments EXIF de l'original sont recopiés.
 *
 * Batch : photos les plus récentes d'abord, plafond par passage ET budget de temps
 * (le CRON tourne dans le verrou de l'orchestrateur, qui émet les alertes chaque minute).
 *
 * @phpstan-type FrameStats array{luma: float, neutral: array{0: float, 1: float, 2: float}|null, samples: list<int>, dark: bool}
 * @phpstan-type FrameParams array{gr: float, gg: float, gb: float, k: float, delta: float, a: float, b: float, target_luma: float, window: int}
 * @phpstan-type IndexEntry array{filename: string, ts: int}
 * @phpstan-type BatchResult array{pending: int, processed: int, skipped: int, skipped_dark: int, failed: int, timed_out: bool, by_slug: array<string, int>}
 */
class GalleryNormalizeService
{
    private const FILENAME_PATTERN = '/^[a-zA-Z0-9_.-]+\.(jpg|jpeg)$/i';

    public const DEFAULT_TIME_BUDGET_SECONDS = 25.0;

    private const DEFAULT_STRENGTH = 0.35;
    private const DEFAULT_JPEG_QUALITY = 90;
    private const DEFAULT_MAX_PER_RUN = 20;
    private const MAX_PER_RUN_CAP = 200;

    private const SAMPLE_GRID = 50;

    private const WB_GAIN_MIN = 0.88;
    private const WB_GAIN_MAX = 1.12;
    private const NEUTRAL_LUMA_MIN = 20.0;
    private const NEUTRAL_LUMA_MAX = 235.0;
    private const NEUTRAL_CHROMA_MAX = 0.25;
    private const NEUTRAL_MIN_RATIO = 0.05;

    private const BRIGHTNESS_TARGET = 120.0;
    private const BRIGHTNESS_DELTA_MAX = 0.10;

    private const CONTRAST_PERCENTILE_LOW = 0.02;
    private const CONTRAST_PERCENTILE_HIGH = 0.98;
    private const CONTRAST_MIN_RANGE = 8;
    private const STRETCH_SCALE_MAX = 1.35;

    /** Luma moyenne en dessous de laquelle une photo est considérée « nuit » et laissée telle quelle. */
    private const DARK_FRAME_LUMA = 32.0;

    /** Fenêtre de lissage : ±2 photos (5 au total, comme la taille par défaut du filtre FFmpeg). */
    private const SMOOTH_RADIUS = 2;
    /** Correction d'exposition maximale du deflicker (±15 %). */
    private const DEFLICKER_MAX = 0.15;
    private const SMOOTH_MAX_GAP_SECONDS = 7200;

    private const STALE_TMP_SECONDS = 3600;
    private const EXIF_READ_BYTES = 262144;

    private GalleryNormalizedStore $store;

    /** @var array<string, FrameStats|null> statistiques par « slug/fichier », le temps d'un batch */
    private array $statsCache = [];

    public function __construct(
        private LogService $logger,
        private ?OperationalSettingsService $operationalSettings = null,
    ) {
        $this->store = new GalleryNormalizedStore();
    }

    /**
     * Chemin à servir pour un fichier galerie : copie normalisée courante si présente,
     * sinon l'original. Null si l'original n'existe plus (photo en corbeille / supprimée) :
     * une copie restante n'est alors jamais servie.
     */
    public function resolveDisplayPath(string $slug, string $filename): ?string
    {
        $this->validateSlug($slug);
        $this->validateFilename($filename);

        $original = $this->store->originalPath($slug, $filename);
        if (!is_file($original) || !is_readable($original)) {
            return null;
        }

        if ($this->store->hasCurrentCopy($slug, $filename)) {
            $copy = $this->store->copyPath($slug, $filename);
            if (is_readable($copy)) {
                return $copy;
            }
        }

        return $original;
    }

    public function isAlreadyProcessed(string $slug, string $filename): bool
    {
        return $this->store->isPurged($slug, $filename) || $this->store->hasCurrentMeta($slug, $filename);
    }

    /**
     * Traite jusqu'à $max photos en attente (toutes galeries, plus récentes d'abord),
     * dans la limite de $timeBudgetSeconds (au moins une photo par appel).
     *
     * @return BatchResult
     */
    public function processBatch(?int $max = null, ?float $timeBudgetSeconds = null): array
    {
        $started = microtime(true);
        $max = max(1, min(self::MAX_PER_RUN_CAP, $max ?? $this->resolveMaxPerRun()));
        $budget = max(0.0, $timeBudgetSeconds ?? self::DEFAULT_TIME_BUDGET_SECONDS);
        $strength = $this->clampStrength($this->resolveStrength());
        $quality = $this->clampQuality($this->resolveJpegQuality());

        foreach (GalleryConfig::getAllowedSlugs() as $slug) {
            $this->store->cleanupStaleTmp($slug, self::STALE_TMP_SECONDS);
        }

        [$indexes, $candidates] = $this->collectCandidates();

        $result = [
            'pending' => count($candidates),
            'processed' => 0,
            'skipped' => 0,
            'skipped_dark' => 0,
            'failed' => 0,
            'timed_out' => false,
            'by_slug' => [],
        ];

        $this->statsCache = [];
        $attempted = 0;
        foreach ($candidates as $candidate) {
            if ($attempted >= $max) {
                break;
            }
            if ($attempted > 0 && (microtime(true) - $started) >= $budget) {
                $result['timed_out'] = true;
                break;
            }
            $attempted++;

            $slug = $candidate['slug'];
            $filename = $indexes[$slug][$candidate['pos']]['filename'];
            try {
                $status = $this->processOne($slug, $indexes[$slug], $candidate['pos'], $strength, $quality);
            } catch (\Throwable $e) {
                $status = 'failed';
                $this->logger->warning("GalleryNormalize: échec {$filename} [{$slug}] — " . $e->getMessage());
            }

            switch ($status) {
                case 'normalized':
                    $result['processed']++;
                    $result['by_slug'][$slug] = ($result['by_slug'][$slug] ?? 0) + 1;
                    break;
                case 'skipped_dark':
                    $result['skipped_dark']++;
                    break;
                case 'already':
                    $result['skipped']++;
                    break;
                default:
                    $result['failed']++;
            }
        }
        $this->statsCache = [];

        $this->logger->info(sprintf(
            'GalleryNormalize: batch terminé en %.1f s — pending=%d processed=%d dark=%d skipped=%d failed=%d%s',
            microtime(true) - $started,
            $result['pending'],
            $result['processed'],
            $result['skipped_dark'],
            $result['skipped'],
            $result['failed'],
            $result['timed_out'] ? ' (budget temps atteint)' : ''
        ));

        return $result;
    }

    /**
     * Remplace les originaux par leur copie normalisée COURANTE (rename atomique) et pose `.done`.
     *
     * - copie orpheline (original absent : photo en corbeille / supprimée) → copie supprimée,
     *   jamais « ressuscitée » dans la galerie ;
     * - copie périmée (algorithme antérieur, sans méta courante) → ignorée (recalculée par le batch).
     *
     * @return array{purged: int, orphans_removed: int, stale: int, failed: int, by_slug: array<string, int>}
     */
    public function purgeNormalizedOriginals(?string $onlySlug = null): array
    {
        if ($onlySlug !== null) {
            $this->validateSlug($onlySlug);
        }

        $result = [
            'purged' => 0,
            'orphans_removed' => 0,
            'stale' => 0,
            'failed' => 0,
            'by_slug' => [],
        ];

        $slugs = $onlySlug !== null ? [$onlySlug] : GalleryConfig::getAllowedSlugs();
        foreach ($slugs as $slug) {
            $normDir = $this->store->dir($slug);
            if (!is_dir($normDir)) {
                continue;
            }

            foreach (scandir($normDir) ?: [] as $name) {
                if (!preg_match(self::FILENAME_PATTERN, $name)) {
                    continue; // métas .json, marqueurs .done, temporaires
                }
                $copy = $normDir . '/' . $name;
                if (!is_file($copy)) {
                    continue;
                }

                $original = $this->store->originalPath($slug, $name);
                if (!is_file($original)) {
                    $this->store->removeDerived($slug, $name);
                    $result['orphans_removed']++;
                    continue;
                }

                if (!$this->store->hasCurrentMeta($slug, $name)) {
                    $result['stale']++;
                    continue;
                }

                // rename() remplace l'original de façon atomique : jamais d'instant sans fichier.
                if (!@rename($copy, $original)) {
                    $result['failed']++;
                    $this->logger->error("GalleryNormalize: impossible de promouvoir {$name} [{$slug}]");
                    continue;
                }
                $marker = json_encode(['algo' => GalleryNormalizedStore::ALGO_VERSION, 'purged_at' => date('c')]);
                if (@file_put_contents($this->store->donePath($slug, $name), (string) $marker) === false) {
                    $this->logger->error("GalleryNormalize: marqueur .done non écrit pour {$name} [{$slug}]");
                }
                @unlink($this->store->metaPath($slug, $name));

                $result['purged']++;
                $result['by_slug'][$slug] = ($result['by_slug'][$slug] ?? 0) + 1;
            }
        }

        $this->logger->info(sprintf(
            'GalleryNormalize: purge originaux — purged=%d orphans_removed=%d stale=%d failed=%d',
            $result['purged'],
            $result['orphans_removed'],
            $result['stale'],
            $result['failed']
        ));

        return $result;
    }

    /**
     * Nombre de photos en attente de normalisation (toutes galeries).
     */
    public function countPending(): int
    {
        return count($this->collectCandidates()[1]);
    }

    /**
     * Normalise une photo précise (lissage avec ses voisines de la même galerie).
     * Retourne true seulement si une copie normalisée a été écrite.
     */
    public function normalizeFile(
        string $slug,
        string $filename,
        ?float $strength = null,
        ?int $quality = null,
    ): bool {
        $this->validateSlug($slug);
        $this->validateFilename($filename);

        $index = $this->buildIndex($slug);
        $pos = null;
        foreach ($index as $i => $entry) {
            if ($entry['filename'] === $filename) {
                $pos = $i;
                break;
            }
        }
        if ($pos === null) {
            return false;
        }

        $this->statsCache = [];
        $status = 'failed';
        try {
            $status = $this->processOne(
                $slug,
                $index,
                $pos,
                $this->clampStrength($strength ?? $this->resolveStrength()),
                $this->clampQuality($quality ?? $this->resolveJpegQuality())
            );
        } finally {
            $this->statsCache = [];
        }

        return $status === 'normalized';
    }

    // ------------------------------------------------------------------
    // Pipeline d'une photo
    // ------------------------------------------------------------------

    /**
     * @param list<IndexEntry> $index
     * @return 'normalized'|'skipped_dark'|'already'|'failed'
     */
    private function processOne(string $slug, array $index, int $pos, float $strength, int $quality): string
    {
        $filename = $index[$pos]['filename'];
        if ($this->isAlreadyProcessed($slug, $filename)) {
            return 'already';
        }

        $source = $this->store->originalPath($slug, $filename);
        if (!is_file($source) || !is_readable($source)) {
            return 'failed';
        }

        $img = @imagecreatefromjpeg($source);
        if ($img === false) {
            $this->logger->warning("GalleryNormalize: JPEG illisible {$filename} [{$slug}]");
            return 'failed';
        }

        $width = imagesx($img);
        $height = imagesy($img);
        if ($width < 10 || $height < 10) {
            imagedestroy($img);
            return 'failed';
        }

        $own = $this->computeStats($img, $width, $height);
        $this->statsCache[$slug . '/' . $filename] = $own;

        if (!$this->store->ensureDir($slug)) {
            imagedestroy($img);
            $this->logger->error('GalleryNormalize: impossible de créer ' . $this->store->dir($slug));
            return 'failed';
        }

        if ($own['dark']) {
            imagedestroy($img);
            // Retire une éventuelle copie d'un algorithme antérieur : l'original sera servi.
            $this->store->removeDerived($slug, $filename);
            $this->writeMeta($slug, $filename, 'skipped_dark', $strength, $quality, [
                'mean_luma' => round($own['luma'], 1),
            ]);
            return 'skipped_dark';
        }

        $params = $this->deriveParams($slug, $index, $pos, $own);
        $this->applyLuts($img, $width, $height, $this->buildLuts($params, $strength));

        $dest = $this->store->copyPath($slug, $filename);
        $tmp = $this->store->tmpPath($dest);
        $ok = imagejpeg($img, $tmp, $quality);
        imagedestroy($img);

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            return 'failed';
        }

        $this->copyExifSegments($source, $tmp);

        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            $this->logger->error("GalleryNormalize: rename atomique échoué pour {$filename} [{$slug}]");
            return 'failed';
        }

        $written = $this->writeMeta($slug, $filename, 'normalized', $strength, $quality, [
            'gains' => [round($params['gr'], 4), round($params['gg'], 4), round($params['gb'], 4)],
            'deflicker' => round($params['k'], 4),
            'delta' => round($params['delta'], 2),
            'levels' => [round($params['a'], 4), round($params['b'], 2)],
            'mean_luma' => round($own['luma'], 1),
            'target_luma' => round($params['target_luma'], 1),
            'smoothing_window' => $params['window'],
        ]);
        if (!$written) {
            // Copie sans méta courante = périmée : ni servie ni promue, recalculée au prochain batch.
            $this->logger->error("GalleryNormalize: méta non écrite pour {$filename} [{$slug}]");
            return 'failed';
        }

        return 'normalized';
    }

    /**
     * Statistiques d'une image sur une grille déterministe (reproductible d'un passage à l'autre).
     *
     * @return FrameStats
     */
    private function computeStats(\GdImage $img, int $width, int $height): array
    {
        $nx = min(self::SAMPLE_GRID, $width);
        $ny = min(self::SAMPLE_GRID, $height);

        $samples = [];
        $sumLuma = 0.0;
        $neutralSum = [0.0, 0.0, 0.0];
        $neutralCount = 0;

        for ($iy = 0; $iy < $ny; $iy++) {
            $y = (int) (($iy + 0.5) * $height / $ny);
            for ($ix = 0; $ix < $nx; $ix++) {
                $x = (int) (($ix + 0.5) * $width / $nx);
                $rgb = imagecolorat($img, $x, $y) & 0xFFFFFF;
                $samples[] = $rgb;
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $luma = $this->luma($r, $g, $b);
                $sumLuma += $luma;

                // Pixel « quasi gris » : saturation HSV faible, ni bouché ni brûlé.
                $hi = max($r, $g, $b);
                $lo = min($r, $g, $b);
                if (
                    $luma >= self::NEUTRAL_LUMA_MIN
                    && $luma <= self::NEUTRAL_LUMA_MAX
                    && $hi > 0
                    && ($hi - $lo) <= self::NEUTRAL_CHROMA_MAX * $hi
                ) {
                    $neutralSum[0] += $r;
                    $neutralSum[1] += $g;
                    $neutralSum[2] += $b;
                    $neutralCount++;
                }
            }
        }

        $total = count($samples);
        $meanLuma = $total > 0 ? $sumLuma / $total : 0.0;

        $neutral = null;
        if ($total > 0 && $neutralCount >= max(10, (int) ceil($total * self::NEUTRAL_MIN_RATIO))) {
            $neutral = [
                $neutralSum[0] / $neutralCount,
                $neutralSum[1] / $neutralCount,
                $neutralSum[2] / $neutralCount,
            ];
        }

        return [
            'luma' => $meanLuma,
            'neutral' => $neutral,
            'samples' => $samples,
            'dark' => $meanLuma < self::DARK_FRAME_LUMA,
        ];
    }

    /**
     * Paramètres de correction d'une photo, dérivés des statistiques de sa fenêtre temporelle
     * (elle-même + voisines proches de la même galerie, poids triangulaires) :
     *
     * - deflicker : gain d'exposition ramenant la photo vers la luminance moyenne de la fenêtre
     *   (borné ±DEFLICKER_MAX) — atténue le scintillement réel entre photos successives ;
     * - balance des blancs, luminosité et niveaux calculés sur la fenêtre : la correction
     *   elle-même ne « saute » pas d'une photo à l'autre ;
     * - niveaux estimés sur l'histogramme APRÈS gains + deflicker + luminosité.
     *
     * @param list<IndexEntry> $index
     * @param FrameStats $own
     * @return FrameParams
     */
    private function deriveParams(string $slug, array $index, int $pos, array $own): array
    {
        $centerTs = $index[$pos]['ts'];
        /** @var list<array{0: int, 1: FrameStats}> $window */
        $window = [[self::SMOOTH_RADIUS + 1, $own]];

        for ($d = 1; $d <= self::SMOOTH_RADIUS; $d++) {
            foreach ([$pos - $d, $pos + $d] as $j) {
                if (!isset($index[$j]) || abs($index[$j]['ts'] - $centerTs) > self::SMOOTH_MAX_GAP_SECONDS) {
                    continue;
                }
                $neighbor = $this->neighborStats($slug, $index[$j]['filename']);
                if ($neighbor === null || $neighbor['dark']) {
                    continue;
                }
                $window[] = [self::SMOOTH_RADIUS + 1 - $d, $neighbor];
            }
        }

        $weightSum = 0;
        $lumaSum = 0.0;
        $neutralSum = [0.0, 0.0, 0.0];
        $neutralWeight = 0;
        foreach ($window as [$w, $stats]) {
            $weightSum += $w;
            $lumaSum += $w * $stats['luma'];
            if ($stats['neutral'] !== null) {
                for ($c = 0; $c < 3; $c++) {
                    $neutralSum[$c] += $w * $stats['neutral'][$c];
                }
                $neutralWeight += $w;
            }
        }
        $targetLuma = $lumaSum / $weightSum;

        // Balance des blancs (gray-world robuste) ; pas de correction si la fenêtre n'a pas
        // assez de pixels neutres (scène dominée par la végétation, par ex.).
        $gains = [1.0, 1.0, 1.0];
        if ($neutralWeight > 0) {
            $means = [
                $neutralSum[0] / $neutralWeight,
                $neutralSum[1] / $neutralWeight,
                $neutralSum[2] / $neutralWeight,
            ];
            $gray = ($means[0] + $means[1] + $means[2]) / 3.0;
            if ($gray >= 1.0) {
                for ($c = 0; $c < 3; $c++) {
                    $gains[$c] = $this->clampGain($gray / max($means[$c], 1.0));
                }
            }
        }

        $k = $this->deflickerGain($own['luma'], $targetLuma);
        $deltaNorm = (self::BRIGHTNESS_TARGET - $targetLuma) / 255.0;
        $delta = max(-self::BRIGHTNESS_DELTA_MAX, min(self::BRIGHTNESS_DELTA_MAX, $deltaNorm)) * 255.0;

        $lowSum = 0.0;
        $highSum = 0.0;
        foreach ($window as [$w, $stats]) {
            [$low, $high] = $this->postPercentiles(
                $stats['samples'],
                $gains,
                $this->deflickerGain($stats['luma'], $targetLuma),
                $delta
            );
            $lowSum += $w * $low;
            $highSum += $w * $high;
        }
        [$a, $b] = $this->partialLevels($lowSum / $weightSum, $highSum / $weightSum);

        return [
            'gr' => $gains[0],
            'gg' => $gains[1],
            'gb' => $gains[2],
            'k' => $k,
            'delta' => $delta,
            'a' => $a,
            'b' => $b,
            'target_luma' => $targetLuma,
            'window' => count($window),
        ];
    }

    private function deflickerGain(float $luma, float $targetLuma): float
    {
        if ($luma < 1.0) {
            return 1.0;
        }

        return max(1.0 - self::DEFLICKER_MAX, min(1.0 + self::DEFLICKER_MAX, $targetLuma / $luma));
    }

    /**
     * Percentiles bas/haut de la luma des échantillons après gains + deflicker + luminosité.
     *
     * @param list<int> $samples pixels 0xRRGGBB
     * @param array{0: float, 1: float, 2: float} $gains
     * @return array{0: int, 1: int}
     */
    private function postPercentiles(array $samples, array $gains, float $k, float $delta): array
    {
        $total = count($samples);
        if ($total < 10) {
            return [0, 255];
        }

        $hist = array_fill(0, 256, 0);
        foreach ($samples as $rgb) {
            $l = (int) round($this->luma(
                $this->clampByte((($rgb >> 16) & 0xFF) * $gains[0] * $k + $delta),
                $this->clampByte((($rgb >> 8) & 0xFF) * $gains[1] * $k + $delta),
                $this->clampByte(($rgb & 0xFF) * $gains[2] * $k + $delta)
            ));
            $hist[max(0, min(255, $l))]++;
        }

        return [
            $this->percentile($hist, $total * self::CONTRAST_PERCENTILE_LOW),
            $this->percentile($hist, $total * self::CONTRAST_PERCENTILE_HIGH),
        ];
    }

    /**
     * Auto-levels PARTIEL : [low, high] est étendu vers [0, 255] d'une fraction alpha choisie
     * pour que le facteur d'étirement ne dépasse jamais STRETCH_SCALE_MAX.
     * Continu et monotone ; une image sombre reste sombre (pas de recentrage).
     *
     * @return array{0: float, 1: float} [a, b] tels que v' = a·v + b
     */
    private function partialLevels(float $low, float $high): array
    {
        $range = $high - $low;
        if ($range < self::CONTRAST_MIN_RANGE || $range >= 255.0) {
            return [1.0, 0.0];
        }

        $alpha = min(1.0, (self::STRETCH_SCALE_MAX - 1.0) * $range / (255.0 - $range));
        $outLow = $low * (1.0 - $alpha);
        $outHigh = $high + (255.0 - $high) * $alpha;
        $a = ($outHigh - $outLow) / $range;

        return [$a, $outLow - $a * $low];
    }

    /**
     * @param array<int, int> $hist
     */
    private function percentile(array $hist, float $count): int
    {
        $cum = 0;
        for ($i = 0; $i < 256; $i++) {
            $cum += $hist[$i];
            if ($cum >= $count) {
                return $i;
            }
        }

        return 255;
    }

    /**
     * @return FrameStats|null
     */
    private function neighborStats(string $slug, string $filename): ?array
    {
        $key = $slug . '/' . $filename;
        if (array_key_exists($key, $this->statsCache)) {
            return $this->statsCache[$key];
        }

        $stats = null;
        // Un original déjà remplacé par sa copie (purge) biaiserait le lissage.
        if (!$this->store->isPurged($slug, $filename)) {
            $path = $this->store->originalPath($slug, $filename);
            $img = is_file($path) ? @imagecreatefromjpeg($path) : false;
            if ($img !== false) {
                $w = imagesx($img);
                $h = imagesy($img);
                if ($w >= 10 && $h >= 10) {
                    $stats = $this->computeStats($img, $w, $h);
                }
                imagedestroy($img);
            }
        }

        $this->statsCache[$key] = $stats;

        return $stats;
    }

    /**
     * Tables de correspondance par canal, valeurs déjà décalées (R<<16, G<<8, B) et bornées.
     *
     * @param FrameParams $p
     * @return array{0: list<int>, 1: list<int>, 2: list<int>}
     */
    private function buildLuts(array $p, float $strength): array
    {
        $gains = [$p['gr'] * $p['k'], $p['gg'] * $p['k'], $p['gb'] * $p['k']];
        $shifts = [16, 8, 0];
        $luts = [[], [], []];
        for ($c = 0; $c < 3; $c++) {
            for ($v = 0; $v < 256; $v++) {
                $corrected = $this->clampByte($p['a'] * $this->clampByte($v * $gains[$c] + $p['delta']) + $p['b']);
                $out = (int) round($v * (1.0 - $strength) + $corrected * $strength);
                $luts[$c][] = max(0, min(255, $out)) << $shifts[$c];
            }
        }

        return $luts;
    }

    /**
     * @param array{0: list<int>, 1: list<int>, 2: list<int>} $luts
     */
    private function applyLuts(\GdImage $img, int $width, int $height, array $luts): void
    {
        [$lutR, $lutG, $lutB] = $luts;
        // Écriture brute des pixels (pas de mélange alpha) — l'image JPEG est opaque.
        imagealphablending($img, false);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                imagesetpixel($img, $x, $y, $lutR[($rgb >> 16) & 0xFF] | $lutG[($rgb >> 8) & 0xFF] | $lutB[$rgb & 0xFF]);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function writeMeta(
        string $slug,
        string $filename,
        string $status,
        float $strength,
        int $quality,
        array $params,
    ): bool {
        $meta = [
            'algo' => GalleryNormalizedStore::ALGO_VERSION,
            'status' => $status,
            'at' => date('c'),
            'strength' => round($strength, 3),
            'quality' => $quality,
            'params' => $params,
        ];

        $path = $this->store->metaPath($slug, $filename);
        $tmp = $this->store->tmpPath($path);
        if (@file_put_contents($tmp, (string) json_encode($meta)) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // EXIF : GD n'écrit aucune métadonnée → recopie des segments APP1 « Exif » de l'original.
    // ------------------------------------------------------------------

    private function copyExifSegments(string $source, string $target): void
    {
        $original = @file_get_contents($source, false, null, 0, self::EXIF_READ_BYTES);
        if (!is_string($original)) {
            return;
        }
        $segments = self::extractExifSegments($original);
        if ($segments === '') {
            return;
        }
        $jpeg = @file_get_contents($target);
        if (!is_string($jpeg)) {
            return;
        }
        $merged = self::injectSegments($jpeg, $segments);
        if ($merged !== $jpeg) {
            @file_put_contents($target, $merged);
        }
    }

    /**
     * Segments APP1 « Exif » (marqueur compris) d'un flux JPEG, jusqu'au début des données (SOS).
     */
    public static function extractExifSegments(string $jpeg): string
    {
        $len = strlen($jpeg);
        if ($len < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
            return '';
        }

        $pos = 2;
        $out = '';
        while ($pos + 4 <= $len) {
            if ($jpeg[$pos] !== "\xFF") {
                break;
            }
            $marker = ord($jpeg[$pos + 1]);
            if ($marker === 0xFF) { // octet de remplissage
                $pos++;
                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) { // SOS / EOI : fin des en-têtes
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { // marqueurs sans longueur
                $pos += 2;
                continue;
            }
            $unpacked = unpack('n', substr($jpeg, $pos + 2, 2));
            $segLen = is_array($unpacked) ? (int) $unpacked[1] : 0;
            if ($segLen < 2 || $pos + 2 + $segLen > $len) {
                break;
            }
            if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\x00\x00") {
                $out .= substr($jpeg, $pos, 2 + $segLen);
            }
            $pos += 2 + $segLen;
        }

        return $out;
    }

    /**
     * Insère des segments juste après SOI (et après l'APP0 JFIF éventuel, convention courante).
     */
    public static function injectSegments(string $jpeg, string $segments): string
    {
        if ($segments === '' || strlen($jpeg) < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
            return $jpeg;
        }

        $insertAt = 2;
        if (substr($jpeg, 2, 2) === "\xFF\xE0") {
            $unpacked = unpack('n', substr($jpeg, 4, 2));
            $app0Len = is_array($unpacked) ? (int) $unpacked[1] : 0;
            if ($app0Len >= 2 && 4 + $app0Len <= strlen($jpeg)) {
                $insertAt = 4 + $app0Len;
            }
        }

        return substr($jpeg, 0, $insertAt) . $segments . substr($jpeg, $insertAt);
    }

    // ------------------------------------------------------------------
    // Inventaire
    // ------------------------------------------------------------------

    /**
     * Index de chaque galerie (ordre chronologique) + photos en attente, plus récentes d'abord.
     *
     * @return array{0: array<string, list<IndexEntry>>, 1: list<array{slug: string, pos: int, ts: int}>}
     */
    private function collectCandidates(): array
    {
        $indexes = [];
        $candidates = [];
        foreach (GalleryConfig::getAllowedSlugs() as $slug) {
            $indexes[$slug] = $this->buildIndex($slug);
            foreach ($indexes[$slug] as $pos => $entry) {
                if (!$this->isAlreadyProcessed($slug, $entry['filename'])) {
                    $candidates[] = ['slug' => $slug, 'pos' => $pos, 'ts' => $entry['ts']];
                }
            }
        }

        // Plus récentes d'abord : la vue par défaut du timelapse (derniers jours) est servie
        // en priorité, puis l'historique est rattrapé à rebours.
        usort(
            $candidates,
            static fn (array $a, array $b): int => [$b['ts'], $b['slug'], $b['pos']] <=> [$a['ts'], $a['slug'], $a['pos']]
        );

        return [$indexes, $candidates];
    }

    /**
     * Photos d'une galerie, triées par date de capture croissante.
     *
     * @return list<IndexEntry>
     */
    private function buildIndex(string $slug): array
    {
        $dir = $this->store->galleryDir($slug);
        if (!is_dir($dir)) {
            return [];
        }

        $items = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '' || $name[0] === '.' || !preg_match(self::FILENAME_PATTERN, $name)) {
                continue; // ., .., .normalized, fichiers cachés
            }
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $items[] = [
                'filename' => $name,
                'ts' => GalleryTimestamp::fromFile($path, $name)->getTimestamp(),
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => [$a['ts'], $a['filename']] <=> [$b['ts'], $b['filename']]
        );

        return $items;
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    private function luma(float $r, float $g, float $b): float
    {
        return $r * 0.299 + $g * 0.587 + $b * 0.114;
    }

    private function clampGain(float $gain): float
    {
        return max(self::WB_GAIN_MIN, min(self::WB_GAIN_MAX, $gain));
    }

    private function clampByte(float $v): float
    {
        return max(0.0, min(255.0, $v));
    }

    private function clampStrength(float $strength): float
    {
        return max(0.0, min(1.0, $strength));
    }

    private function clampQuality(int $quality): int
    {
        return max(60, min(95, $quality));
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
