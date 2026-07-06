<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\Version;
use App\Repository\NavPageRepository;
use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SupervisionController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private NavPageRepository $navPageRepository
    ) {
    }

    /**
     * Affiche la page de supervision avec tous les liens
     */
    public function show(Request $request, Response $response): Response
    {
        $adminCacheToken = $_ENV['ADMIN_CACHE_TOKEN'] ?? '';

        // État serveur des pages du menu, pour cocher chaque switch au chargement.
        $navStates = [];
        try {
            $navStates = $this->navPageRepository->getAllStates();
        } catch (\Throwable) {
            $navStates = [];
        }

        $html = $this->renderer->render('supervision.twig', [
            'page_title' => 'Supervision - n3 iot datas',
            'nav_active' => 'supervision',
            'admin_cache_token' => $adminCacheToken,
            'version' => Version::getWithPrefix(),
            'nav_states' => $navStates,
        ]);

        $response->getBody()->write($html);
        return $response;
    }
}
