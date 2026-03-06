<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SupervisionController
{
    public function __construct(
        private TemplateRenderer $renderer
    ) {
    }

    /**
     * Affiche la page de supervision avec tous les liens
     */
    public function show(Request $request, Response $response): Response
    {
        $adminCacheToken = $_ENV['ADMIN_CACHE_TOKEN'] ?? '';
        $html = $this->renderer->render('supervision.twig', [
            'page_title' => 'Supervision - n3 iot datas',
            'active_page' => 'supervision',
            'admin_cache_token' => $adminCacheToken
        ]);

        $response->getBody()->write($html);
        return $response;
    }
}
