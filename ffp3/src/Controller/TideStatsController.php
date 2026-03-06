<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Config\Version;
use App\Security\CsrfService;
use App\Service\TemplateRenderer;
use App\Service\TideAnalysisService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TideStatsController
{
    public function __construct(
        private TideAnalysisService $tideService,
        private TemplateRenderer $renderer,
        private CsrfService $csrfService
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        // Période : même logique que page aquaponie (24h par défaut)
        $endDefault = date('Y-m-d H:i:s');
        $startDefault = date('Y-m-d H:i:s', strtotime('-1 day', strtotime($endDefault)));

        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody() ?? [];
            
            // Validation CSRF
            $submittedToken = $body['_csrf_token'] ?? null;
            if (!$this->csrfService->validateToken($submittedToken)) {
                $response->getBody()->write('Token CSRF invalide. Veuillez recharger la page et réessayer.');
                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
            
            // Validation des dates
            $startDateInput = $body['start_date'] ?? '';
            $startTimeInput = $body['start_time'] ?? '00:00:00';
            $endDateInput = $body['end_date'] ?? '';
            $endTimeInput = $body['end_time'] ?? '23:59:59';
            
            $startDate = $startDateInput . ' ' . $startTimeInput;
            $endDate   = $endDateInput   . ' ' . $endTimeInput;
        } else {
            $startDate = $startDefault;
            $endDate   = $endDefault;
        }

        $stats = $this->tideService->compute($startDate, $endDate);

        // Statistiques hebdomadaires sur 6 mois (≈ 26 semaines)
        $sixMonthsAgo = date('Y-m-d H:i:s', strtotime('-6 months', strtotime($endDate)));
        $weeklyStats  = $this->tideService->computeWeeklySeries($sixMonthsAgo, $endDate);

        $weeklyStatsJson = json_encode($weeklyStats, JSON_THROW_ON_ERROR);

        // Environnement actuel
        $environment = TableConfig::getEnvironment();

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

            // Nouveaux graphiques
            'weekly_stats_json' => $weeklyStatsJson,
            
            // Version du projet
            'version' => Version::getWithPrefix(),
            'environment' => $environment,
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
} 