<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Page de description du module MSP1 (station météo / Le potager).
 */
class MspDescriptionController
{
    public function __construct(
        private TemplateRenderer $renderer
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $html = $this->renderer->render('msp_description.twig', [
            'page_title' => 'Caractéristiques du module MSP1 - n3 iot datas',
            'hero_title' => 'Caractéristiques du module MSP1',
            'hero_subtitle' => 'Description du système, des capteurs, actionneurs et logiciels — Station météo du potager',
            'hero_icon' => 'fa-sun',
            'data_url' => '/meteo',
            'footer_text' => 'Station météo (Le potager) MSP1',
            'nav_active' => 'potager',
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
