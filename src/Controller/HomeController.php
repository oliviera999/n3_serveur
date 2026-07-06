<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\NavPageRepository;
use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HomeController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private NavPageRepository $navPageRepository
    ) {
    }

    /**
     * Affiche la page d'accueil N3 IoT Datas
     */
    public function show(Request $request, Response $response): Response
    {
        // État des switchs (table navPages) : les tuiles de la home suivent la
        // même visibilité que les liens du menu. Défaut visible si non renseigné.
        $navStates = [];
        try {
            $navStates = $this->navPageRepository->getAllStates();
        } catch (\Throwable) {
            $navStates = [];
        }

        $html = $this->renderer->render('home.twig', [
            'page_title' => 'n3 iot datas - olution',
            'nav_active' => 'home',
            'active_page' => 'home',
            'version' => Version::getWithPrefix(),
            'environment' => TableConfig::getDefaultEnvironment(),
            'nav_states' => $navStates,
        ]);

        $response->getBody()->write($html);
        return $response;
    }
}
