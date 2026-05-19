<?php

declare(strict_types=1);

namespace App\Controller\Pgl;

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

    public function showBySecret(Request $request, Response $response, array $args): Response
    {
        $secret = (string) ($args['secret'] ?? '');
        if ($secret === '' || !$this->isValidToken($secret)) {
            return $response->withStatus(404);
        }

        $hourly = $this->repository->getHourlyStats(72);
        $daily = $this->repository->getDailyStats(60);
        $total = $this->repository->getTotalCount();

        $html = $this->renderer->render('pgl_stats.twig', [
            'page_title' => 'Poissonglouton - Statistiques',
            'nav_active' => '',
            'active_page' => '',
            'is_hidden_page' => true,
            'hourly_stats' => $hourly,
            'daily_stats' => $daily,
            'total_count' => $total,
            'environment' => $_ENV['ENV'] ?? 'prod',
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    private function isValidToken(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) {
            return false;
        }
        if ($this->repository->isValidSecretToken($token)) {
            return true;
        }
        $fallback = (string) ($_ENV['PGL_STATS_TOKEN'] ?? '');
        return $fallback !== '' && hash_equals($fallback, $token);
    }
}
