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
        $systemHealth = $showOnline
            ? $this->repository->getSystemHealth(PglConfig::ONLINE_THRESHOLD_SECONDS)
            : null;

        $html = $this->renderer->render('pgl_stats.twig', [
            'page_title' => 'Poissonglouton - Statistiques',
            'nav_active' => 'pgl',
            'active_page' => 'pgl',
            'hourly_stats' => $this->repository->getHourlyStats(72),
            'daily_stats' => $this->repository->getDailyStats(60),
            'total_count' => $this->repository->getTotalCount(),
            'environment' => $_ENV['ENV'] ?? 'prod',
            'show_online_status' => $showOnline,
            'system_health' => $systemHealth,
        ]);

        $response->getBody()->write($html);
        return $response;
    }
}
