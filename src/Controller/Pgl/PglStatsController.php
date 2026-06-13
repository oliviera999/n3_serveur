<?php

declare(strict_types=1);

namespace App\Controller\Pgl;

use App\Config\PglConfig;
use App\Repository\PglRepository;
use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PglStatsController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private PglRepository $repository,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $showOnline = PglConfig::SHOW_ONLINE_STATUS_ON_PAGE && PglConfig::ONLINE_CHECK_ENABLED;

        // Résilience : si les tables PGL n'existent pas encore en base (module récent,
        // migration CREATE_PGL_TABLES non appliquée), on rend la page avec des valeurs
        // neutres plutôt que de laisser remonter une PDOException (erreur 500).
        try {
            $hourlyStats = $this->repository->getHourlyStats(72);
            $dailyStats = $this->repository->getDailyStats(60);
            $totalCount = $this->repository->getTotalCount();
            $systemHealth = $showOnline
                ? $this->repository->getSystemHealth(PglConfig::ONLINE_THRESHOLD_SECONDS)
                : null;
        } catch (\Throwable) {
            $hourlyStats = [];
            $dailyStats = [];
            $totalCount = 0;
            $systemHealth = null;
        }

        $html = $this->renderer->render('pgl_stats.twig', [
            'page_title' => 'Poissonglouton - Statistiques',
            'nav_active' => 'pgl',
            'active_page' => 'pgl',
            'hourly_stats' => $hourlyStats,
            'daily_stats' => $dailyStats,
            'total_count' => $totalCount,
            'environment' => $_ENV['ENV'] ?? 'prod',
            'show_online_status' => $showOnline,
            'system_health' => $systemHealth,
        ]);

        $response->getBody()->write($html);
        return $response;
    }
}
