<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Repository\MspSensorRepository;
use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Page d'affichage des données station météo (msp2_5).
 * Route: GET /msp1/msp1datas/msp1-data.php
 */
class MspDataController
{
    private const BOARD = 2;

    public function __construct(
        private TemplateRenderer $renderer,
        private MspSensorRepository $sensorRepo,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $latest = $this->sensorRepo->getLatest();
        $recent = $this->sensorRepo->getRecent(30);

        $html = $this->renderer->render('msp1_data.twig', [
            'page_title' => 'Données station météo - Le potager',
            'latest' => $latest,
            'recent' => $recent,
            'board' => self::BOARD,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
