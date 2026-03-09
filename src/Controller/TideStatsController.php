<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Config\Version;
use App\Service\DateRangeExtractor;
use App\Service\TemplateRenderer;
use App\Service\TideAnalysisService;
use App\Util\RealtimeUrlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TideStatsController
{
    public function __construct(
        private TideAnalysisService $tideService,
        private TemplateRenderer $renderer,
        private DateRangeExtractor $dateRangeExtractor,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $endDefault = date('Y-m-d H:i:s');
        $startDefault = date('Y-m-d H:i:s', strtotime('-1 day', strtotime($endDefault)));

        try {
            [$startDate, $endDate] = $this->dateRangeExtractor->extract($request, $startDefault, $endDefault);
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'CSRF') !== false) {
                $response->getBody()->write('Token CSRF invalide. Veuillez recharger la page et réessayer.');
                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
            throw $e;
        }

        $stats = $this->tideService->compute($startDate, $endDate);

        $sixMonthsAgo = date('Y-m-d H:i:s', strtotime('-6 months', strtotime($endDate)));
        $weeklyStats  = $this->tideService->computeWeeklySeries($sixMonthsAgo, $endDate);
        $weeklyStatsJson = json_encode($weeklyStats, JSON_THROW_ON_ERROR);

        $environment = TableConfig::getEnvironment();
        $realtime_api_base = RealtimeUrlHelper::getRealtimeApiBase($environment);

        $html = $this->renderer->render('tide_stats.twig', [
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'marnage_moyen'    => $stats['marnage_moyen'],
            'frequence_marees' => $stats['frequence_marees'],
            'cycles'           => $stats['cycles'],
            'reserve_pos'      => $stats['reserve_pos'],
            'reserve_neg'      => $stats['reserve_neg'],
            'reserve_var'      => $stats['reserve_var'],
            'diff_maree'       => $stats['diff_maree'],
            'weekly_stats_json' => $weeklyStatsJson,
            'version' => Version::getWithPrefix(),
            'environment' => $environment,
            'realtime_api_base' => $realtime_api_base,
            'nav_active' => 'tide_stats',
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
