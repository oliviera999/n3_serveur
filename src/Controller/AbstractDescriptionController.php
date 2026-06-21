<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur abstrait pour les pages de description des modules IoT (MSP1, N3PP).
 * Factorise le rendu du template de description ; chaque sous-classe fournit
 * uniquement le template cible et les libellés spécifiques (titres, icône, etc.).
 */
abstract class AbstractDescriptionController
{
    public function __construct(
        protected TemplateRenderer $renderer
    ) {
    }

    /**
     * Nom du template Twig de description (ex. 'msp_description.twig').
     */
    abstract protected function descriptionTemplate(): string;

    /**
     * Variables spécifiques au module passées au template
     * (page_title, hero_title, hero_subtitle, hero_icon, data_url, footer_text, nav_active).
     *
     * @return array<string, mixed>
     */
    abstract protected function descriptionData(): array;

    public function show(Request $request, Response $response): Response
    {
        $html = $this->renderer->render($this->descriptionTemplate(), $this->descriptionData());

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
