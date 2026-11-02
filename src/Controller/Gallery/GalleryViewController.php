<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Config\GalleryConfig;
use App\Config\Paths;
use App\Repository\GalleryControlRepository;
use App\Repository\NavPageRepository;
use App\Service\GalleryNormalizeService;
use App\Service\TemplateRenderer;
use App\Util\GalleryTimestamp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pages de consultation des galeries photo (MSP1, N3PP, FFP3).
 * Style identique au site actuel ; images servies via route dédiée (uploads hors public).
 */
class GalleryViewController
{
    private const PER_PAGE = 12;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg'];

    /** Plafond API photos timelapse (évite payloads / OOM navigateur). */
    private const PHOTOS_DEFAULT_LIMIT = 800;
    private const PHOTOS_MAX_LIMIT = 3000;
    private const PHOTOS_MAX_STEP = 60;

    /** Cache navigateur d'une URL versionnée (`?v=`) : le contenu ne change jamais sous cette URL. */
    private const CACHE_IMMUTABLE = 'public, max-age=31536000, immutable';
    /** URL non versionnée (grille, landing) : courte, revalidée par ETag (copie normalisée visible vite). */
    private const CACHE_REVALIDATE = 'public, max-age=3600';

    /** Racine du projet serveur (parent de src/) */
    private string $baseDir;

    public function __construct(
        private TemplateRenderer $renderer,
        private GalleryControlRepository $galleryControlRepository,
        private NavPageRepository $navPageRepository,
        private GalleryNormalizeService $normalizeService,
    ) {
        $this->baseDir = Paths::getProjectRoot();
    }

    /**
     * Version firmware uploadphotosserver (GPIO 100) — alignée page contrôle galerie et POST version.
     */
    private function firmwareVersionForSlug(string $slug): string
    {
        try {
            return $this->galleryControlRepository->getFirmwareVersion($slug) ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Répertoire d'une galerie — même résolution que la normalisation et la corbeille
     * (`GalleryConfig::resolveUploadDir`), pour servir et lister exactement les mêmes fichiers.
     */
    private function getUploadDir(string $slug): string
    {
        if (!GalleryConfig::isValidSlug($slug)) {
            throw new \InvalidArgumentException('Galerie inconnue');
        }

        return $this->baseDir . '/' . rtrim(GalleryConfig::resolveUploadDir($slug), '/');
    }

    /**
     * Pagination compacte : 1 … pages voisines … dernière (évite des dizaines de liens).
     *
     * @return list<array{type: 'ellipsis'}|array{type: 'page', num: int}>
     */
    private function buildGalleryPaginationItems(int $current, int $last, int $neighbors = 2): array
    {
        if ($last < 2) {
            return [];
        }
        if ($last <= 9) {
            $out = [];
            for ($p = 1; $p <= $last; $p++) {
                $out[] = ['type' => 'page', 'num' => $p];
            }

            return $out;
        }

        $items = [['type' => 'page', 'num' => 1]];
        $start = max(2, $current - $neighbors);
        $end = min($last - 1, $current + $neighbors);

        if ($start > 2) {
            $items[] = ['type' => 'ellipsis'];
        }
        for ($p = $start; $p <= $end; $p++) {
            $items[] = ['type' => 'page', 'num' => $p];
        }
        if ($end < $last - 1) {
            $items[] = ['type' => 'ellipsis'];
        }
        $items[] = ['type' => 'page', 'num' => $last];

        return $items;
    }

    /**
     * Liste les fichiers image d'un dossier (ordre antéchronologique).
     *
     * @return list<string> noms de fichiers uniquement
     */
    private function listImageFiles(string $uploadDir): array
    {
        return array_map(static fn (array $e): string => $e['name'], $this->listImageEntries($uploadDir));
    }

    /**
     * Fichiers image + date de capture, du plus récent au plus ancien.
     *
     * Tri sur la date de capture du nom de fichier (segment `Y-m-d_H-i-s`, ou mtime si absent),
     * calculée UNE fois par fichier (auparavant recalculée à chaque comparaison du tri).
     * Ne plus trier principalement sur le compteur seq : un reset NVS / compteur bas
     * (ex. seq 37 après des fichiers seq 460) enterrait les dernières photos en page N.
     * À timestamps égaux : seq décroissant (N-first), sinon nom décroissant.
     *
     * @return list<array{name: string, ts: int}>
     */
    private function listImageEntries(string $uploadDir): array
    {
        if (!is_dir($uploadDir)) {
            return [];
        }

        $entries = [];
        foreach (scandir($uploadDir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $path = $uploadDir . '/' . $name;
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true) || !is_file($path)) {
                continue;
            }
            [$group, $seq] = $this->filenameSortKey($name);
            $entries[] = [
                'name' => $name,
                'ts' => GalleryTimestamp::fromFile($path, $name)->getTimestamp(),
                'group' => $group,
                'seq' => $seq,
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['ts'] !== $b['ts']) {
                return $b['ts'] <=> $a['ts'];
            }
            if ($a['group'] === 1 && $b['group'] === 1 && $a['seq'] !== $b['seq']) {
                return $b['seq'] <=> $a['seq'];
            }

            return strcmp($b['name'], $a['name']);
        });

        return array_map(static fn (array $e): array => ['name' => $e['name'], 'ts' => $e['ts']], $entries);
    }

    /**
     * @return array{0:int, 1:int} [groupe (1 = N-first, 0 = legacy), compteur]
     */
    private function filenameSortKey(string $filename): array
    {
        if (preg_match('/^(\d+)_/', $filename, $m) === 1) {
            return [1, (int) $m[1]];
        }

        return [0, 0];
    }

    /** Page index : landing avec 3 blocs (aquaponie, potager, élevage) + dernière photo de chaque galerie. */
    public function showIndex(Request $request, Response $response): Response
    {
        $basePath = trim((string) ($GLOBALS['base_path'] ?? ''), '/');
        $pathPrefix = $basePath !== '' ? '/' . $basePath . '/' : '/';

        $galleries = [
            [
                'slug' => 'ffp3',
                'title' => 'Photos aquaponie (FFP3)',
                'icon' => 'fa-fish',
                'description' => "La caméra du bassin aquaponie capture régulièrement l'état des plantes, des poissons et du système. Les photos permettent de suivre la croissance et l'évolution du projet.",
                'url' => $pathPrefix . 'gallery/ffp3',
            ],
            [
                'slug' => 'msp1',
                'title' => 'Photos du potager (MSP1)',
                'icon' => 'fa-sun',
                'description' => "La caméra de la station météo enregistre les conditions du potager et des cultures. Idéal pour observer la météo, la lumière et l'état des plantes au fil du temps.",
                'url' => $pathPrefix . 'gallery/msp1',
            ],
            [
                'slug' => 'n3pp',
                'title' => "Photos de l'élevage (N3PP)",
                'icon' => 'fa-leaf',
                'description' => "La serre et l'élevage d'insectes sont filmés par une caméra ESP32-CAM. Suivez l'évolution des cultures et de l'environnement en temps réel.",
                'url' => $pathPrefix . 'gallery/n3pp',
            ],
        ];

        // Visibilité pilotée par les switchs de la page supervision (table navPages).
        // `gallery-<slug>` : afficher la galerie ; `gallery-control-<slug>` : afficher
        // le lien de contrôle caméra (réservé aux admins côté template).
        $navStates = [];
        try {
            $navStates = $this->navPageRepository->getAllStates();
        } catch (\Throwable) {
            $navStates = [];
        }

        $visibleGalleries = [];
        foreach ($galleries as $g) {
            $slug = $g['slug'];
            // Absente de navStates => visible par défaut ; masquée seulement si explicitement désactivée.
            if (($navStates['gallery-' . $slug] ?? true) === false) {
                continue;
            }
            $g['show_control'] = ($navStates['gallery-control-' . $slug] ?? true) !== false;
            $g['control_url'] = $pathPrefix . 'gallery/' . $slug . '/control';

            try {
                $uploadDir = $this->getUploadDir($slug);
                $files = $this->listImageFiles($uploadDir);
                $g['last_photo'] = $files[0] ?? null;
                $g['last_photo_url'] = $g['last_photo']
                    ? $pathPrefix . 'gallery/' . $slug . '/files/' . $g['last_photo']
                    : null;
            } catch (\Throwable) {
                $g['last_photo'] = null;
                $g['last_photo_url'] = null;
            }

            $visibleGalleries[] = $g;
        }

        $html = $this->renderer->render('gallery_landing.twig', [
            'page_title' => 'Galeries photos - n3 iot datas',
            'active_page' => 'gallery',
            'nav_active' => 'gallery',
            'galleries' => $visibleGalleries,
            'base_path' => $basePath !== '' ? '/' . $basePath : '',
            'environment' => $_ENV['ENV'] ?? 'prod',
            'firmware_version' => '',
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    /** Galerie photo (grille) — route admin /admin/gallery/{slug}. */
    public function showGalleryAdmin(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $meta = [
            'msp1' => ['title' => 'Photos du potager – station météo', 'back' => '/meteo', 'nav_active' => 'gallery'],
            'n3pp' => ['title' => "Photos de l'élevage d'insectes (N3PP)", 'back' => '/serre', 'nav_active' => 'gallery'],
            'ffp3' => ['title' => 'Photos du potager aquaponie (FFP3)', 'back' => '/aquaponie', 'nav_active' => 'gallery'],
        ];
        if (!isset($meta[$slug])) {
            return $response->withStatus(404);
        }
        return $this->showGallery(
            $request,
            $response,
            $slug,
            $meta[$slug]['title'],
            $meta[$slug]['title'],
            $meta[$slug]['back'],
            'gallery'
        );
    }

    /** Page par défaut : timelapse 24h x2. */
    public function showMsp1(Request $request, Response $response): Response
    {
        return $this->showTimelapse($request, $response, ['slug' => 'msp1']);
    }

    public function showN3pp(Request $request, Response $response): Response
    {
        return $this->showTimelapse($request, $response, ['slug' => 'n3pp']);
    }

    public function showFfp3(Request $request, Response $response): Response
    {
        return $this->showTimelapse($request, $response, ['slug' => 'ffp3']);
    }

    /** Page timelapse pour une galerie (slug msp1, n3pp, ffp3). */
    public function showTimelapse(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $meta = [
            'msp1' => ['title' => 'Timelapse potager (MSP1)', 'back' => '/meteo', 'nav_active' => 'gallery'],
            'n3pp' => ['title' => 'Timelapse élevage (N3PP)', 'back' => '/serre', 'nav_active' => 'gallery'],
            'ffp3' => ['title' => 'Timelapse aquaponie (FFP3)', 'back' => '/aquaponie', 'nav_active' => 'gallery'],
        ];
        if (!isset($meta[$slug])) {
            return $response->withStatus(404);
        }
        $basePath = trim((string) ($GLOBALS['base_path'] ?? ''), '/');
        $pathPrefix = $basePath !== '' ? '/' . $basePath . '/' : '/';
        $apiUrl = $pathPrefix . 'api/gallery/' . $slug . '/photos';
        $apiLatestUrl = $pathPrefix . 'api/gallery/' . $slug . '/latest';
        $galleryAdminUrl = $pathPrefix . 'admin/gallery/' . $slug;
        $html = $this->renderer->render('gallery_timelapse.twig', [
            'page_title' => $meta[$slug]['title'] . ' - n3 iot datas',
            'gallery_slug' => $slug,
            'gallery_title' => $meta[$slug]['title'],
            'back_url' => $pathPrefix . ltrim((string) $meta[$slug]['back'], '/'),
            'gallery_admin_url' => $galleryAdminUrl,
            'api_photos_url' => $apiUrl,
            'api_latest_url' => $apiLatestUrl,
            'nav_active' => $meta[$slug]['nav_active'],
            'active_page' => 'gallery',
            'environment' => $_ENV['ENV'] ?? 'prod',
            'firmware_version' => $this->firmwareVersionForSlug($slug),
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    private function showGallery(Request $request, Response $response, string $slug, string $navLabel, string $pageTitle, string $backUrl, string $navActive): Response
    {
        try {
            $params = $request->getQueryParams();
            $page = max(1, (int) ($params['page'] ?? 1));

            $uploadDir = $this->getUploadDir($slug);
            $allFiles = $this->listImageFiles($uploadDir);
            $total = count($allFiles);
            $maxPage = $total > 0 ? (int) ceil($total / self::PER_PAGE) : 1;
            $page = min($page, $maxPage);
            $offset = ($page - 1) * self::PER_PAGE;
            $files = array_slice($allFiles, $offset, self::PER_PAGE);

            $html = $this->renderer->render('gallery.twig', [
                'page_title' => $pageTitle . ' - n3 iot datas',
                'active_page' => 'gallery',
                'gallery_slug' => $slug,
                'gallery_title' => $pageTitle,
                'gallery_description' => 'Les photos sont prises automatiquement par une caméra ESP32-CAM et publiées ici.',
                'images' => $files,
                'current_page' => $page,
                'max_page' => $maxPage,
                'pagination_items' => $this->buildGalleryPaginationItems($page, $maxPage),
                'total_images' => $total,
                'back_url' => $backUrl,
                'nav_label' => $navLabel,
                'nav_active' => $navActive,
                'environment' => $_ENV['ENV'] ?? 'prod',
                'firmware_version' => $this->firmwareVersionForSlug($slug),
            ]);

            $response->getBody()->write($html);
            return $response;
        } catch (\Throwable $e) {
            error_log(sprintf('[Gallery] slug=%s — %s: %s in %s:%d', $slug, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            throw $e;
        }
    }

    /**
     * API JSON : bornes temporelles des photos stockées (plus récente / plus ancienne).
     * GET /api/gallery/{slug}/latest
     * Retourne {
     *   timestamp, filename,
     *   oldest_timestamp, oldest_filename,
     *   count
     * }.
     */
    public function latestPhoto(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        if (!in_array($slug, ['msp1', 'n3pp', 'ffp3'], true)) {
            return $response->withStatus(404);
        }

        $emptyPayload = [
            'timestamp' => null,
            'filename' => null,
            'oldest_timestamp' => null,
            'oldest_filename' => null,
            'count' => 0,
        ];

        try {
            $uploadDir = $this->getUploadDir($slug);
        } catch (\Throwable) {
            $response->getBody()->write(json_encode($emptyPayload));
            return $response->withHeader('Content-Type', 'application/json');
        }

        if (!is_dir($uploadDir) || !is_readable($uploadDir)) {
            $response->getBody()->write(json_encode($emptyPayload));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $entries = $this->listImageEntries($uploadDir);
        if ($entries === []) {
            $response->getBody()->write(json_encode($emptyPayload));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $latest = $entries[0];
        $oldest = $entries[count($entries) - 1];
        $response->getBody()->write(json_encode([
            'timestamp' => $this->formatTimestamp($latest['ts']),
            'filename' => $latest['name'],
            'oldest_timestamp' => $this->formatTimestamp($oldest['ts']),
            'oldest_filename' => $oldest['name'],
            'count' => count($entries),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API JSON : liste des photos dans une plage temporelle.
     * GET /api/gallery/{slug}/photos?from=ISO&to=ISO[&limit=800][&step=1]
     * Retourne { photos: [{url, timestamp}], meta: { matched, returned, truncated, limit, step, effective_step } }.
     *
     * Échantillonnage UNIFORME sur toute la plage (première et dernière photo incluses) :
     * `step` garde ~1 photo sur N ; si cela dépasse `limit`, le pas est augmenté automatiquement
     * (`truncated` = true, `effective_step` = pas réel) au lieu de couper la fin de la plage.
     * Les URL portent `?v=` (jeton du fichier réellement servi : original ou copie normalisée),
     * ce qui permet un cache navigateur `immutable` sans jamais mélanger d'anciennes versions.
     */
    public function listPhotos(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        if (!in_array($slug, ['msp1', 'n3pp', 'ffp3'], true)) {
            return $response->withStatus(404);
        }

        $params = $request->getQueryParams();
        $fromStr = $params['from'] ?? '';
        $toStr = $params['to'] ?? '';
        if ($fromStr === '' || $toStr === '') {
            $response->getBody()->write(json_encode(['error' => 'Paramètres from et to requis (ISO 8601)']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $from = new \DateTimeImmutable($fromStr);
            $to = new \DateTimeImmutable($toStr);
        } catch (\Throwable) {
            $response->getBody()->write(json_encode(['error' => 'Dates invalides (format ISO 8601 attendu)']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if ($from >= $to) {
            $response->getBody()->write(json_encode(['error' => 'from doit être strictement avant to']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $limit = (int) ($params['limit'] ?? self::PHOTOS_DEFAULT_LIMIT);
        if ($limit < 1) {
            $limit = self::PHOTOS_DEFAULT_LIMIT;
        }
        $limit = min($limit, self::PHOTOS_MAX_LIMIT);

        $step = (int) ($params['step'] ?? 1);
        if ($step < 1) {
            $step = 1;
        }
        $step = min($step, self::PHOTOS_MAX_STEP);

        $emptyMeta = [
            'matched' => 0,
            'returned' => 0,
            'truncated' => false,
            'limit' => $limit,
            'step' => $step,
        ];

        $uploadDir = $this->getUploadDir($slug);
        if (!is_dir($uploadDir) || !is_readable($uploadDir)) {
            $response->getBody()->write(json_encode(['photos' => [], 'meta' => $emptyMeta]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $basePath = trim((string) ($GLOBALS['base_path'] ?? ''), '/');
        $pathPrefix = ($basePath !== '' ? '/' . $basePath . '/' : '/') . 'gallery/' . $slug . '/files/';

        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        $matched = [];
        foreach ($this->listImageEntries($uploadDir) as $entry) {
            if ($entry['ts'] >= $fromTs && $entry['ts'] <= $toTs) {
                $matched[] = $entry;
            }
        }
        // Tri chronologique NUMÉRIQUE (une comparaison de chaînes ISO se trompe au passage à l'heure d'hiver).
        usort($matched, static fn (array $a, array $b): int => [$a['ts'], $a['name']] <=> [$b['ts'], $b['name']]);

        $matchedCount = count($matched);
        $wanted = (int) ceil($matchedCount / $step);
        $target = min($limit, $wanted);
        $truncated = $target < $wanted;

        $photos = [];
        foreach (self::uniformSampleIndices($matchedCount, $target) as $i) {
            $name = $matched[$i]['name'];
            $photos[] = [
                'url' => $pathPrefix . $name . $this->versionQuery($slug, $name),
                'timestamp' => $this->formatTimestamp($matched[$i]['ts']),
            ];
        }

        $payload = [
            'photos' => $photos,
            'meta' => [
                'matched' => $matchedCount,
                'returned' => count($photos),
                'truncated' => $truncated,
                'limit' => $limit,
                'step' => $step,
                'effective_step' => $photos !== [] ? round($matchedCount / count($photos), 1) : (float) $step,
            ],
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Indices de $k éléments répartis uniformément parmi $n (premier et dernier inclus).
     *
     * @return list<int>
     */
    public static function uniformSampleIndices(int $n, int $k): array
    {
        if ($n <= 0 || $k <= 0) {
            return [];
        }
        if ($k >= $n) {
            return range(0, $n - 1);
        }
        if ($k === 1) {
            return [$n - 1]; // la plus récente
        }

        $indices = [];
        $ratio = ($n - 1) / ($k - 1); // >= 1 → indices strictement croissants
        for ($i = 0; $i < $k; $i++) {
            $indices[] = (int) round($i * $ratio);
        }

        return $indices;
    }

    private function formatTimestamp(int $ts): string
    {
        return (new \DateTimeImmutable())->setTimestamp($ts)->format('c');
    }

    /**
     * `?v=<jeton>` du fichier réellement servi (copie normalisée ou original), '' si introuvable.
     */
    private function versionQuery(string $slug, string $filename): string
    {
        try {
            $path = $this->normalizeService->resolveDisplayPath($slug, $filename);
        } catch (\InvalidArgumentException) {
            return '';
        }

        return $path !== null ? '?v=' . $this->fileToken($path) : '';
    }

    private function fileToken(string $path): string
    {
        $mtime = @filemtime($path) ?: 0;
        $size = @filesize($path) ?: 0;

        return dechex((int) $mtime) . '-' . dechex((int) $size);
    }

    /**
     * If-None-Match : liste séparée par des virgules, préfixe faible `W/` toléré, `*` accepté.
     */
    private static function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        if (trim($ifNoneMatch) === '*') {
            return true;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sert un fichier image de la galerie (uploads hors public).
     * Sécurisé : pas de path traversal (filename validé).
     */
    public function serveImage(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $filename = $args['filename'] ?? '';

        if (!in_array($slug, ['msp1', 'n3pp', 'ffp3'], true)) {
            return $response->withStatus(404);
        }

        // Uniquement noms de fichiers sûrs (alphanum, tiret, underscore, point) — extension
        // insensible à la casse, comme le listing (`listImageEntries`).
        if (!preg_match('/^[a-zA-Z0-9_.-]+\\.(jpg|jpeg)$/i', $filename)) {
            return $response->withStatus(400);
        }

        // Copie normalisée courante si le cron l'a produite ; jamais pour une photo
        // dont l'original a disparu (corbeille) — cf. resolveDisplayPath().
        $path = $this->normalizeService->resolveDisplayPath($slug, $filename);
        if ($path === null) {
            return $response->withStatus(404);
        }

        $token = $this->fileToken($path);
        $etag = '"' . $token . '"';
        $requested = (string) ($request->getQueryParams()['v'] ?? '');
        // URL versionnée à jour → cache long ; sinon cache court revalidé par ETag.
        $cacheControl = $requested !== '' && hash_equals($token, $requested)
            ? self::CACHE_IMMUTABLE
            : self::CACHE_REVALIDATE;

        $inm = $request->getHeaderLine('If-None-Match');
        if ($inm !== '' && self::etagMatches($inm, $etag)) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', $cacheControl);
        }

        $stream = fopen($path, 'r');
        if ($stream === false) {
            return $response->withStatus(500);
        }

        $body = $response->getBody();
        $body->rewind();
        $body->write((string) stream_get_contents($stream));
        fclose($stream);

        return $response
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $cacheControl);
    }
}
