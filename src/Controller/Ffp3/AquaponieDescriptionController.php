<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AquaponieDescriptionController
{
    public function __construct(
        private TemplateRenderer $renderer
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $html = $this->renderer->render('aquaponie_description.twig', [
            'page_title' => 'Caractéristiques du module FFP3 - n3 iot datas',
            'images_base' => '/ffp3/assets/images/aquaponie-description',
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
