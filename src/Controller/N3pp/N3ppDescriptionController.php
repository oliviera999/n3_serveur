<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Page de description du module N3PP (serre / élevage d'insectes).
 */
class N3ppDescriptionController
{
    public function __construct(
        private TemplateRenderer $renderer
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $html = $this->renderer->render('n3pp_description.twig', [
            'page_title' => "Caractéristiques du module N3PP - n3 iot datas",
            'hero_title' => "Caractéristiques du module N3PP",
            'hero_subtitle' => "Description du système, des capteurs, actionneurs et logiciels — Serre / élevage d'insectes",
            'hero_icon' => 'fa-leaf',
            'data_url' => '/serre',
            'footer_text' => "Serre / élevage d'insectes (N3PP)",
            'nav_active' => 'elevage',
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
