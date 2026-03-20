<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Config\GalleryConfig;
use App\Service\GalleryTrashService;
use App\Service\TemplateRenderer;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Administration de la corbeille photo (admin uniquement).
 * Routes sous /admin/gallery/{slug}/trash/*.
 */
class GalleryTrashController
{
    private const PER_PAGE = 24;

    public function __construct(
        private GalleryTrashService $trashService,
        private TemplateRenderer $renderer,
    ) {
    }

    /**
     * GET /admin/gallery/{slug}/trash — page HTML de la corbeille.
     */
    public function showTrash(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        if (!$this->isValidSlug($slug)) {
            return $response->withStatus(404);
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));

        $allFiles = $this->trashService->listTrashed($slug);
        $total = count($allFiles);
        $maxPage = $total > 0 ? (int) ceil($total / self::PER_PAGE) : 1;
        $page = min($page, $maxPage);
        $offset = ($page - 1) * self::PER_PAGE;
        $files = array_slice($allFiles, $offset, self::PER_PAGE);

        $slugLabels = GalleryConfig::getSlugLabels();
        $counts = $this->trashService->countAllTrashed();

        $html = $this->renderer->render('gallery_trash.twig', [
            'page_title' => 'Corbeille photos — ' . GalleryConfig::getLabel($slug) . ' - n3 iot datas',
            'active_page' => 'supervision',
            'nav_active' => 'supervision',
            'gallery_slug' => $slug,
            'gallery_title' => 'Corbeille — ' . GalleryConfig::getLabel($slug),
            'images' => $files,
            'current_page' => $page,
            'max_page' => $maxPage,
            'total_images' => $total,
            'slugs' => GalleryConfig::getAllowedSlugs(),
            'slug_labels' => $slugLabels,
            'trash_counts' => $counts,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * GET /admin/gallery/{slug}/trash/files/{filename} — sert une image de la corbeille.
     */
    public function serveTrashImage(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $filename = $args['filename'] ?? '';

        if (!$this->isValidSlug($slug) || !$this->isValidFilename($filename)) {
            return $response->withStatus(400);
        }

        $path = $this->trashService->getTrashImagePath($slug, $filename);
        if ($path === null) {
            return $response->withStatus(404);
        }

        $stream = fopen($path, 'r');
        if ($stream === false) {
            return $response->withStatus(500);
        }

        $body = $response->getBody();
        $body->write(stream_get_contents($stream));
        fclose($stream);

        return $response
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('Cache-Control', 'private, no-cache');
    }

    /**
     * POST /admin/api/gallery/{slug}/move-to-trash — déplace une photo vers la corbeille.
     * Body JSON : { "filename": "..." } ou { "filenames": ["...", "..."] }
     */
    public function apiMoveToTrash(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        if (!$this->isValidSlug($slug)) {
            return ResponseHelper::error($response, 'Galerie invalide', 404);
        }

        $body = (array) $request->getParsedBody();
        $filenames = $this->extractFilenames($body);
        if ($filenames === []) {
            return ResponseHelper::error($response, 'Aucun fichier spécifié', 400);
        }

        $moved = 0;
        $failed = 0;
        $reason = $body['reason'] ?? 'manual';
        foreach ($filenames as $f) {
            if ($this->isValidFilename($f) && $this->trashService->moveToTrash($slug, $f, $reason)) {
                $moved++;
            } else {
                $failed++;
            }
        }

        return ResponseHelper::success($response, [
            'moved' => $moved,
            'failed' => $failed,
        ]);
    }

    /**
     * POST /admin/api/gallery/{slug}/restore — restaure depuis la corbeille.
     * Body JSON : { "filename": "..." } ou { "filenames": ["...", "..."] }
     */
    public function apiRestore(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        if (!$this->isValidSlug($slug)) {
            return ResponseHelper::error($response, 'Galerie invalide', 404);
        }

        $body = (array) $request->getParsedBody();
        $filenames = $this->extractFilenames($body);
        if ($filenames === []) {
            return ResponseHelper::error($response, 'Aucun fichier spécifié', 400);
        }

        $restored = 0;
        $failed = 0;
        foreach ($filenames as $f) {
            if ($this->isValidFilename($f) && $this->trashService->restore($slug, $f)) {
                $restored++;
            } else {
                $failed++;
            }
        }

        return ResponseHelper::success($response, [
            'restored' => $restored,
            'failed' => $failed,
        ]);
    }

    /**
     * POST /admin/api/gallery/{slug}/purge — supprime définitivement.
     * Body JSON : { "filename": "..." } ou { "filenames": ["...", "..."] }
     */
    public function apiPurge(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        if (!$this->isValidSlug($slug)) {
            return ResponseHelper::error($response, 'Galerie invalide', 404);
        }

        $body = (array) $request->getParsedBody();
        $filenames = $this->extractFilenames($body);
        if ($filenames === []) {
            return ResponseHelper::error($response, 'Aucun fichier spécifié', 400);
        }

        $result = $this->trashService->purgeSelected($slug, $filenames);
        return ResponseHelper::success($response, $result);
    }

    /**
     * POST /admin/api/gallery/{slug}/purge-all — vide toute la corbeille d'une galerie.
     */
    public function apiPurgeAll(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        if (!$this->isValidSlug($slug)) {
            return ResponseHelper::error($response, 'Galerie invalide', 404);
        }

        $result = $this->trashService->purgeAll($slug);
        return ResponseHelper::success($response, $result);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function extractFilenames(array $body): array
    {
        if (!empty($body['filenames']) && is_array($body['filenames'])) {
            return array_values(array_filter($body['filenames'], 'is_string'));
        }
        if (!empty($body['filename']) && is_string($body['filename'])) {
            return [$body['filename']];
        }
        return [];
    }

    private function isValidSlug(string $slug): bool
    {
        return GalleryConfig::isValidSlug($slug);
    }

    private function isValidFilename(string $filename): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_.-]+\.(jpg|jpeg)$/', $filename);
    }
}
