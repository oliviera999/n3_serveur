<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Repository\N3ppSensorRepository;
use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Page d'affichage des données serre / élevage (n3pp4_2).
 * Route: GET /n3pp/n3ppdatas/n3pp-data.php
 */
class N3ppDataController
{
    private const BOARD = 3;

    public function __construct(
        private TemplateRenderer $renderer,
        private N3ppSensorRepository $sensorRepo,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $latest = $this->sensorRepo->getLatest();
        $recent = $this->sensorRepo->getRecent(30);

        $html = $this->renderer->render('n3pp_data.twig', [
            'page_title' => 'Données serre / élevage - n3 iot',
            'latest' => $latest,
            'recent' => $recent,
            'board' => self::BOARD,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
