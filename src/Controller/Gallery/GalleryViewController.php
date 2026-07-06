<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Config\Paths;
use App\Repository\GalleryControlRepository;
use App\Repository\NavPageRepository;
use App\Service\TemplateRenderer;
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

    /** Racine du projet serveur (parent de src/) */
    private string $baseDir;

    public function __construct(
        private TemplateRenderer $renderer,
        private GalleryControlRepository $galleryControlRepository,
        private NavPageRepository $navPageRepository,
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

    private function getUploadDir(string $slug): string
    {
        $envKey = $slug === 'msp1' ? 'GALLERY_MSP1_DIR'
            : ($slug === 'n3pp' ? 'GALLERY_N3PP_DIR'
            : ($slug === 'ffp3' ? 'GALLERY_FFP3_DIR'
            : null));
        if ($envKey === null) {
            throw new \InvalidArgumentException('Galerie inconnue');
        }
        $dir = $_ENV[$envKey] ?? 'uploads/' . $slug;
        return $this->baseDir . '/' . rtrim($dir, '/');
    }

    /**
     * Liste les fichiers image d'un dossier (ordre antéchronologique).
     * @return list<string> noms de fichiers uniquement
     */
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

    private function listImageFiles(string $uploadDir): array
    {
        if (!is_dir($uploadDir)) {
            return [];
        }
        $files = [];
        foreach (scandir($uploadDir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, self::ALLOWED_EXTENSIONS, true) && is_file($uploadDir . '/' . $name)) {
                $files[] = $name;
            }
        }
        $this->sortNewestFirst($files);
        return $files;
    }

    /**
     * Trie les noms du plus récent au plus ancien, robuste à un horodatage faux.
     *
     * Clé primaire : le compteur monotone `<N>` en tête de nom (format N-first), décroissant.
     * Les fichiers N-first passent avant les fichiers legacy (date-first), eux triés par nom
     * décroissant. Ainsi l'ordre de capture est respecté même si la date embarquée est erronée
     * et même en cas de mélange ancien/nouveau format pendant la transition.
     *
     * @param array<int, string> $files
     */
    private function sortNewestFirst(array &$files): void
    {
        usort($files, function (string $a, string $b): int {
            [$groupA, $seqA] = $this->filenameSortKey($a);
            [$groupB, $seqB] = $this->filenameSortKey($b);
            if ($groupA !== $groupB) {
                return $groupB <=> $groupA; // N-first (1) avant legacy (0)
            }
            if ($groupA === 1) {
                return $seqB <=> $seqA; // par compteur décroissant
            }

            return strcmp($b, $a); // legacy : par nom (date) décroissant
        });
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

        $allFiles = $this->listImageFiles($uploadDir);
        if ($allFiles === []) {
            $response->getBody()->write(json_encode($emptyPayload));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $filename = $allFiles[0];
        $oldestFilename = $allFiles[count($allFiles) - 1];
        $ts = $this->extractTimestampFromFilename($uploadDir . '/' . $filename, $filename);
        $oldestTs = $this->extractTimestampFromFilename($uploadDir . '/' . $oldestFilename, $oldestFilename);
        $response->getBody()->write(json_encode([
            'timestamp' => $ts->format('c'),
            'filename' => $filename,
            'oldest_timestamp' => $oldestTs->format('c'),
            'oldest_filename' => $oldestFilename,
            'count' => count($allFiles),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API JSON : liste des photos dans une plage temporelle.
     * GET /api/gallery/{slug}/photos?from=ISO&to=ISO
     * Retourne [{url, timestamp}].
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

        $uploadDir = $this->getUploadDir($slug);
        if (!is_dir($uploadDir) || !is_readable($uploadDir)) {
            $response->getBody()->write(json_encode([]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $allFiles = $this->listImageFiles($uploadDir);
        $basePath = trim((string) ($GLOBALS['base_path'] ?? ''), '/');
        $pathPrefix = ($basePath !== '' ? '/' . $basePath . '/' : '/') . 'gallery/' . $slug . '/files/';

        $photos = [];
        foreach ($allFiles as $filename) {
            $ts = $this->extractTimestampFromFilename($uploadDir . '/' . $filename, $filename);
            if ($ts >= $from && $ts <= $to) {
                $photos[] = [
                    'url' => $pathPrefix . $filename,
                    'timestamp' => $ts->format('c'),
                ];
            }
        }
        usort($photos, fn ($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        $response->getBody()->write(json_encode($photos));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Extrait la date d'un fichier : format Y-m-d_H-i-s_*.jpg ou filemtime().
     */
    private function extractTimestampFromFilename(string $fullPath, string $filename): \DateTimeImmutable
    {
        $match = [];
        // Tolère un préfixe compteur optionnel `<N>_` (format N-first), puis la date de capture.
        if (preg_match('/^(?:\d+_)?(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})_/', $filename, $match)) {
            try {
                return new \DateTimeImmutable($match[1] . ' ' . $match[2] . ':' . $match[3] . ':' . $match[4]);
            } catch (\Throwable) {
                // fallback
            }
        }
        $mtime = @filemtime($fullPath) ?: time();
        return (new \DateTimeImmutable())->setTimestamp($mtime);
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

        // Uniquement noms de fichiers sûrs (alphanum, tiret, underscore, point)
        if (!preg_match('/^[a-zA-Z0-9_.-]+\\.(jpg|jpeg)$/', $filename)) {
            return $response->withStatus(400);
        }

        $uploadDir = $this->getUploadDir($slug);
        $path = $uploadDir . '/' . $filename;

        if (!is_file($path) || !is_readable($path)) {
            return $response->withStatus(404);
        }

        $stream = fopen($path, 'r');
        if ($stream === false) {
            return $response->withStatus(500);
        }

        $body = $response->getBody();
        $body->rewind();
        $body->write(stream_get_contents($stream));
        fclose($stream);

        return $response
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
