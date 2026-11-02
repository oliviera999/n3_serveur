<?php

declare(strict_types=1);

namespace App\Controller\Gallery;

use App\Config\GalleryConfig;
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

    /** Marge laissée sous `max_execution_time` pour sérialiser la réponse. */
    private const EXECUTION_MARGIN_SECONDS = 10;

    public function __construct(
        private GalleryNormalizeService $normalizeService,
    ) {
    }

    /**
     * POST /admin/api/gallery/normalize-batch
     * Body JSON optionnel : { "max": 20 }
     *
     * Le batch s'arrête de lui-même avant `max_execution_time` (budget de temps) :
     * la requête renvoie toujours un JSON, même sur un hébergement limité à 30 s.
     */
    public function apiNormalizeBatch(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $max = null;
        if (isset($body['max']) && is_numeric($body['max'])) {
            $max = max(1, min(self::MANUAL_MAX_CAP, (int) $body['max']));
        }

        $result = $this->normalizeService->processBatch($max, $this->webTimeBudget());
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
        if (!empty($body['slug'])) {
            if (!is_string($body['slug']) || !GalleryConfig::isValidSlug($body['slug'])) {
                return ResponseHelper::error($response, 'Galerie inconnue', 400);
            }
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

    private function webTimeBudget(): float
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit <= 0) {
            return GalleryNormalizeService::DEFAULT_TIME_BUDGET_SECONDS;
        }

        return (float) max(5, min((int) GalleryNormalizeService::DEFAULT_TIME_BUDGET_SECONDS, $limit - self::EXECUTION_MARGIN_SECONDS));
    }
}
