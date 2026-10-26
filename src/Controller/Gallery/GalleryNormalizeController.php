<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Service\GalleryNormalizeService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Actions admin : normalisation manuelle et purge des originaux (après copie .normalized/).
 */
class GalleryNormalizeController
{
    private const MANUAL_MAX_CAP = 100;

    public function __construct(
        private GalleryNormalizeService $normalizeService,
    ) {
    }

    /**
     * POST /admin/api/gallery/normalize-batch
     * Body JSON optionnel : { "max": 20 }
     */
    public function apiNormalizeBatch(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $max = null;
        if (isset($body['max']) && is_numeric($body['max'])) {
            $max = max(1, min(self::MANUAL_MAX_CAP, (int) $body['max']));
        }

        $result = $this->normalizeService->processBatch($max);
        $result['still_pending'] = $this->normalizeService->countPending();

        return ResponseHelper::success($response, $result);
    }

    /**
     * POST /admin/api/gallery/purge-normalized-originals
     * Body JSON optionnel : { "slug": "msp1" } — sinon toutes les galeries.
     */
    public function apiPurgeNormalizedOriginals(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $slug = null;
        if (!empty($body['slug']) && is_string($body['slug'])) {
            $slug = $body['slug'];
        }

        $result = $this->normalizeService->purgeNormalizedOriginals($slug);

        return ResponseHelper::success($response, $result);
    }

    /**
     * GET /admin/api/gallery/normalize-status — compteur en attente.
     */
    public function apiNormalizeStatus(Request $request, Response $response): Response
    {
        return ResponseHelper::success($response, [
            'pending' => $this->normalizeService->countPending(),
        ]);
    }
}
