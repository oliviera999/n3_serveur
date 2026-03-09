<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\CsrfService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Extraction et validation de plage de dates depuis une requête HTTP.
 * Factorise la logique commune à Aquaponie, MspData, N3ppData, TideStats.
 */
class DateRangeExtractor
{
    public function __construct(
        private CsrfService $csrfService
    ) {
    }

    /**
     * Extrait la plage de dates depuis la requête POST.
     *
     * Supporte :
     * - Format datetime-local (start_datetime / end_datetime)
     * - Format date + time séparés (start_date + start_time / end_date + end_time)
     *
     * @param Request $request   Requête PSR-7
     * @param string  $defaultStart Date de début par défaut
     * @param string  $defaultEnd   Date de fin par défaut
     * @param bool    $validateCsrf Valider le token CSRF (true par défaut)
     * @return array{0: string, 1: string} [startDate, endDate]
     * @throws \RuntimeException Si le token CSRF est invalide et $validateCsrf est true
     */
    public function extract(
        Request $request,
        string $defaultStart,
        string $defaultEnd,
        bool $validateCsrf = true
    ): array {
        if ($request->getMethod() !== 'POST') {
            return [$defaultStart, $defaultEnd];
        }

        $body = $request->getParsedBody() ?? [];

        if ($validateCsrf) {
            $token = $body['_csrf_token'] ?? null;
            if (!$this->csrfService->validateToken($token)) {
                throw new \RuntimeException('Token CSRF invalide');
            }
        }

        // Format datetime-local (prioritaire)
        $sd = $body['start_datetime'] ?? null;
        $ed = $body['end_datetime'] ?? null;
        if ($sd && $ed) {
            return [
                str_replace('T', ' ', $sd) . ':00',
                str_replace('T', ' ', $ed) . ':00',
            ];
        }

        // Format date + time séparés (legacy)
        $startDate = $body['start_date'] ?? null;
        $endDate = $body['end_date'] ?? null;
        $startTime = $body['start_time'] ?? '00:00:00';
        $endTime = $body['end_time'] ?? '23:59:59';

        if ($startDate && $endDate) {
            return [
                $startDate . ' ' . $startTime,
                $endDate . ' ' . $endTime,
            ];
        }

        return [$defaultStart, $defaultEnd];
    }
}
