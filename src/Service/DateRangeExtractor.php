<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\CsrfService;
use App\Util\DisplayTime;
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

        // Format datetime-local (prioritaire).
        // Les champs sont saisis/affichés en heure de Casablanca (cf. filtre Twig `localdt`
        // et setPeriod) : on reconvertit en heure serveur pour le requêtage SQL.
        $sd = $body['start_datetime'] ?? null;
        $ed = $body['end_datetime'] ?? null;
        if ($sd && $ed) {
            return [
                DisplayTime::toServer(str_replace('T', ' ', $sd) . ':00'),
                DisplayTime::toServer(str_replace('T', ' ', $ed) . ':00'),
            ];
        }

        // Format date + time séparés (legacy), également saisis en heure de Casablanca.
        $startDate = $body['start_date'] ?? null;
        $endDate = $body['end_date'] ?? null;
        $startTime = $body['start_time'] ?? '00:00:00';
        $endTime = $body['end_time'] ?? '23:59:59';

        if ($startDate && $endDate) {
            return [
                DisplayTime::toServer($startDate . ' ' . $startTime),
                DisplayTime::toServer($endDate . ' ' . $endTime),
            ];
        }

        return [$defaultStart, $defaultEnd];
    }

    /**
     * Extrait la plage de dates depuis les paramètres GET (query string).
     * Utilisé par ExportController (/export-data?start=...&end=...).
     *
     * @return array{0: string, 1: string} [startDate, endDate] au format Y-m-d H:i:s
     * @throws \RuntimeException Si start et end sont fournis mais invalides
     */
    public function extractFromQuery(Request $request, string $defaultStart, string $defaultEnd): array
    {
        $params = $request->getQueryParams();
        $start = $params['start'] ?? null;
        $end = $params['end'] ?? null;

        if ($start !== null || $end !== null) {
            $startVal = $start ?? '-1 day';
            $endVal = $end ?? 'now';
            try {
                $startDate = (new \DateTimeImmutable($startVal))->format('Y-m-d H:i:s');
                $endDate = (new \DateTimeImmutable($endVal))->format('Y-m-d H:i:s');
                return [$startDate, $endDate];
            } catch (\Exception $e) {
                throw new \RuntimeException('Paramètres de date invalides', 0, $e);
            }
        }

        return [$defaultStart, $defaultEnd];
    }

    /**
     * Durées de fenêtre acceptées pour la navigation par fenêtre glissante.
     * Clé = jeton accepté dans ?window= ; valeur = nombre de secondes.
     * Liste fermée (allow-list) : tout autre jeton est ignoré et l'on retombe
     * sur la plage par défaut (dégradation propre, aucune régression).
     *
     * @var array<string, int>
     */
    private const WINDOW_DURATIONS = [
        '1h' => 3600,
        '3h' => 10800,
        '6h' => 21600,
        '12h' => 43200,
        '1d' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    /**
     * Borne défensive du décalage de fenêtre (évite un offset absurde qui
     * remonterait à des dates hors de propos). 520 ≈ 10 ans de fenêtres 7j.
     */
    private const MAX_WINDOW_OFFSET = 520;

    /**
     * Résout la fenêtre temporelle effective d'une page de données à partir des
     * query params GET, en restant strictement rétro-compatible.
     *
     * Modes pris en charge (par ordre de priorité) :
     *  1. ?from=...&to=... : fenêtre explicite (mêmes dates renvoyées telles quelles).
     *  2. ?window=7d[&offset=N] : fenêtre glissante de durée `window`, décalée de
     *     N pas vers le passé (offset 0 = fenêtre la plus récente se terminant à
     *     l'ancre = $defaultEnd). offset négatif borné à 0.
     *  3. aucun param reconnu : renvoie [$defaultStart, $defaultEnd] inchangés
     *     (comportement historique strictement identique).
     *
     * La valeur de retour fournit aussi des métadonnées de navigation pour
     * construire les liens « fenêtre précédente / suivante » côté template.
     *
     * @return array{
     *     start: string,
     *     end: string,
     *     mode: 'default'|'explicit'|'window',
     *     window: string|null,
     *     offset: int,
     *     has_prev: bool,
     *     has_next: bool,
     *     prev_offset: int|null,
     *     next_offset: int|null
     * }
     */
    public function resolveDataWindow(Request $request, string $defaultStart, string $defaultEnd): array
    {
        $params = $request->getQueryParams();

        // Mode 1 : fenêtre explicite from/to (alias query historiques start/end tolérés).
        $from = $params['from'] ?? $params['start'] ?? null;
        $to = $params['to'] ?? $params['end'] ?? null;
        if (($from !== null && $from !== '') || ($to !== null && $to !== '')) {
            try {
                $startVal = ($from !== null && $from !== '') ? $from : $defaultStart;
                $endVal = ($to !== null && $to !== '') ? $to : $defaultEnd;
                $start = (new \DateTimeImmutable((string) $startVal))->format('Y-m-d H:i:s');
                $end = (new \DateTimeImmutable((string) $endVal))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                // Paramètres illisibles : dégradation propre vers la plage par défaut.
                return $this->defaultWindow($defaultStart, $defaultEnd);
            }

            // Fenêtre explicite : on dérive une largeur pour proposer prev/next cohérents.
            $widthSeconds = max(1, strtotime($end) - strtotime($start));

            return [
                'start' => $start,
                'end' => $end,
                'mode' => 'explicit',
                'window' => null,
                'offset' => 0,
                'has_prev' => true,
                'has_next' => false,
                'prev_offset' => null,
                'next_offset' => null,
                'width_seconds' => $widthSeconds,
            ];
        }

        // Mode 2 : fenêtre glissante window + offset.
        $windowToken = isset($params['window']) ? (string) $params['window'] : null;
        if ($windowToken !== null && isset(self::WINDOW_DURATIONS[$windowToken])) {
            $duration = self::WINDOW_DURATIONS[$windowToken];
            $offset = $this->normalizeOffset($params['offset'] ?? 0);

            // Ancre = fin par défaut (dernière mesure connue). offset 0 => fenêtre
            // la plus récente. Chaque pas recule d'une largeur de fenêtre complète.
            $anchorEnd = strtotime($defaultEnd);
            if ($anchorEnd === false) {
                $anchorEnd = time();
            }
            $end = $anchorEnd - $offset * $duration;
            $start = $end - $duration;

            return [
                'start' => date('Y-m-d H:i:s', $start),
                'end' => date('Y-m-d H:i:s', $end),
                'mode' => 'window',
                'window' => $windowToken,
                'offset' => $offset,
                'has_prev' => $offset < self::MAX_WINDOW_OFFSET,
                'has_next' => $offset > 0,
                'prev_offset' => $offset < self::MAX_WINDOW_OFFSET ? $offset + 1 : null,
                'next_offset' => $offset > 0 ? $offset - 1 : null,
                'width_seconds' => $duration,
            ];
        }

        // Mode 3 : aucun paramètre reconnu => comportement historique inchangé.
        return $this->defaultWindow($defaultStart, $defaultEnd);
    }

    /**
     * @return array{
     *     start: string, end: string, mode: 'default', window: null, offset: int,
     *     has_prev: bool, has_next: bool, prev_offset: int|null, next_offset: int|null,
     *     width_seconds: int
     * }
     */
    private function defaultWindow(string $defaultStart, string $defaultEnd): array
    {
        $width = strtotime($defaultEnd) - strtotime($defaultStart);

        return [
            'start' => $defaultStart,
            'end' => $defaultEnd,
            'mode' => 'default',
            'window' => null,
            'offset' => 0,
            // Depuis la fenêtre par défaut on peut toujours reculer ; pas de « suivant ».
            'has_prev' => true,
            'has_next' => false,
            'prev_offset' => null,
            'next_offset' => null,
            'width_seconds' => max(1, $width),
        ];
    }

    /**
     * Normalise et borne le paramètre offset (entier >= 0, plafonné).
     */
    private function normalizeOffset(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return 0;
        }
        $offset = (int) $raw;
        if ($offset < 0) {
            return 0;
        }
        if ($offset > self::MAX_WINDOW_OFFSET) {
            return self::MAX_WINDOW_OFFSET;
        }

        return $offset;
    }
}
