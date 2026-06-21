<?php

declare(strict_types=1);

namespace Tests\Controller\Msp;

use App\Controller\Msp\MspDescriptionController;
use App\Controller\N3pp\N3ppDescriptionController;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Couvre la factorisation portée par AbstractDescriptionController, vue à travers
 * les deux sous-classes concrètes (MSP1 et N3PP). Vérifie que chaque sous-classe
 * passe son template et ses libellés spécifiques, et que la réponse HTML (corps +
 * Content-Type) reste identique au contrat firmware/front.
 */
final class MspDescriptionControllerTest extends TestCase
{
    private function request()
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/meteo-description');
    }

    private function response()
    {
        return (new ResponseFactory())->createResponse();
    }

    public function testMspRendersMspTemplateWithSpecificData(): void
    {
        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->expects($this->once())
            ->method('render')
            ->with(
                'msp_description.twig',
                $this->callback(static function (array $data): bool {
                    return $data['hero_icon'] === 'fa-sun'
                        && $data['data_url'] === '/meteo'
                        && $data['nav_active'] === 'potager'
                        && $data['page_title'] === 'Caractéristiques du module MSP1 - n3 iot datas';
                })
            )
            ->willReturn('<html>MSP</html>');

        $controller = new MspDescriptionController($renderer);
        $result = $controller->show($this->request(), $this->response());

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $result->getHeaderLine('Content-Type'));
        $this->assertSame('<html>MSP</html>', (string) $result->getBody());
    }

    public function testN3ppRendersN3ppTemplateWithSpecificData(): void
    {
        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->expects($this->once())
            ->method('render')
            ->with(
                'n3pp_description.twig',
                $this->callback(static function (array $data): bool {
                    return $data['hero_icon'] === 'fa-leaf'
                        && $data['data_url'] === '/serre'
                        && $data['nav_active'] === 'elevage';
                })
            )
            ->willReturn('<html>N3PP</html>');

        $controller = new N3ppDescriptionController($renderer);
        $result = $controller->show($this->request(), $this->response());

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $result->getHeaderLine('Content-Type'));
        $this->assertSame('<html>N3PP</html>', (string) $result->getBody());
    }
}
