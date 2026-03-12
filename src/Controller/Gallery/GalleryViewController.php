<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Config\Paths;
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
    ) {
        $this->baseDir = Paths::getProjectRoot();
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
        rsort($files);
        return $files;
    }

    /** Page index : présente les différentes galeries (aquaponie, potager, élevage). */
    public function showIndex(Request $request, Response $response): Response
    {
        $html = $this->renderer->render('gallery_index.twig', [
            'page_title' => 'Galeries photos - n3 iot datas',
            'active_page' => 'gallery',
            'nav_active' => 'gallery',
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    public function showMsp1(Request $request, Response $response): Response
    {
        return $this->showGallery($request, $response, 'msp1', 'Le potager (MSP1)', 'Photos du potager – station météo', '/meteo', 'gallery');
    }

    public function showN3pp(Request $request, Response $response): Response
    {
        return $this->showGallery($request, $response, 'n3pp', "L'élevage d'insectes (N3PP)", "Photos de l'élevage d'insectes", '/serre', 'gallery');
    }

    public function showFfp3(Request $request, Response $response): Response
    {
        return $this->showGallery($request, $response, 'ffp3', "L'aquaponie (FFP3)", 'Photos du potager aquaponie', '/aquaponie', 'gallery');
    }

    /** Page timelapse pour une galerie (slug msp1, n3pp, ffp3). */
    public function showTimelapse(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $meta = [
            'msp1' => ['title' => 'Timelapse potager (MSP1)', 'back' => '/meteo', 'nav_active' => 'gallery'],
            'n3pp' => ['title' => "Timelapse élevage (N3PP)", 'back' => '/serre', 'nav_active' => 'gallery'],
            'ffp3' => ['title' => 'Timelapse aquaponie (FFP3)', 'back' => '/aquaponie', 'nav_active' => 'gallery'],
        ];
        if (!isset($meta[$slug])) {
            return $response->withStatus(404);
        }
        $basePath = trim((string) ($GLOBALS['base_path'] ?? ''), '/');
        $pathPrefix = $basePath !== '' ? '/' . $basePath . '/' : '/';
        $apiUrl = $pathPrefix . 'api/gallery/' . $slug . '/photos';
        $galleryUrl = $pathPrefix . 'gallery/' . $slug;
        $html = $this->renderer->render('gallery_timelapse.twig', [
            'page_title' => $meta[$slug]['title'] . ' - n3 iot datas',
            'gallery_slug' => $slug,
            'gallery_title' => $meta[$slug]['title'],
            'back_url' => $pathPrefix . ltrim((string) $meta[$slug]['back'], '/'),
            'gallery_url' => $galleryUrl,
            'api_photos_url' => $apiUrl,
            'nav_active' => $meta[$slug]['nav_active'],
            'active_page' => 'gallery',
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
                'total_images' => $total,
                'back_url' => $backUrl,
                'nav_label' => $navLabel,
                'nav_active' => $navActive,
            ]);

            $response->getBody()->write($html);
            return $response;
        } catch (\Throwable $e) {
            error_log(sprintf('[Gallery] slug=%s — %s: %s in %s:%d', $slug, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            throw $e;
        }
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
        if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})_/', $filename, $match)) {
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
