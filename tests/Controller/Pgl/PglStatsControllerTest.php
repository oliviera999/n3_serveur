<?php

declare(strict_types=1);

namespace Tests\Controller\Pgl;

use App\Controller\Pgl\PglStatsController;
use App\Repository\PglRepository;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PglStatsControllerTest extends TestCase
{
    public function testShowReturns200WithRenderedStats(): void
    {
        $repo = $this->createMock(PglRepository::class);
        $repo->method('getEventsBetween')->willReturn([]);
        $repo->method('countEventsBetween')->willReturn(0);
        $repo->method('getRunningTotalBefore')->willReturn(0);
        $repo->method('getLatestEvent')->willReturn(null);

        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->expects($this->once())
            ->method('render')
            ->with(
                'pgl_stats.twig',
                $this->callback(static function (array $vars): bool {
                    return ($vars['nav_active'] ?? '') === 'pgl'
                        && isset($vars['reading_time'])
                        && is_array($vars['reading_time'])
                        && count($vars['reading_time']) === 72
                        && isset($vars['count_delta_series'])
                        && is_array($vars['count_delta_series'])
                        && count($vars['count_delta_series']) === 72;
                })
            )
            ->willReturn('<html>ok</html>');

        $controller = new PglStatsController($renderer, $repo);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/pgl');
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->show($request, $response);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('ok', (string) $result->getBody());
    }
}
