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
    public function testReturns404WhenTokenIsInvalid(): void
    {
        $repo = $this->createMock(PglRepository::class);
        $repo->method('isValidSecretToken')->willReturn(false);

        $renderer = $this->createMock(TemplateRenderer::class);
        $controller = new PglStatsController($renderer, $repo);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/pgl/invalid');
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->showBySecret($request, $response, ['secret' => 'invalid-token']);
        $this->assertSame(404, $result->getStatusCode());
    }

    public function testReturns200WhenTokenIsValid(): void
    {
        $repo = $this->createMock(PglRepository::class);
        $repo->method('isValidSecretToken')->willReturn(true);
        $repo->method('getHourlyStats')->willReturn([]);
        $repo->method('getDailyStats')->willReturn([]);
        $repo->method('getTotalCount')->willReturn(12);

        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->method('render')->willReturn('<html>ok</html>');

        $controller = new PglStatsController($renderer, $repo);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/pgl/a1b2c3d4e5f6a7b8');
        $response = (new ResponseFactory())->createResponse();

        $result = $controller->showBySecret($request, $response, ['secret' => 'a1b2c3d4e5f6a7b8']);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('ok', (string) $result->getBody());
    }
}
