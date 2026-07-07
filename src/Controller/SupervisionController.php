<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\Version;
use App\Repository\NavPageRepository;
use App\Service\HmacAuditLogger;
use App\Service\HmacPolicyService;
use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SupervisionController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private NavPageRepository $navPageRepository,
        private HmacAuditLogger $hmacAuditLogger,
        private HmacPolicyService $hmacPolicyService,
    ) {
    }

    /**
     * Affiche la page de supervision avec tous les liens
     */
    public function show(Request $request, Response $response): Response
    {
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
            'version' => Version::getWithPrefix(),
            'nav_states' => $navStates,
            'hmac_audit_enabled' => $this->hmacAuditLogger->isEnabled(),
            'hmac_audit_entry_count' => $this->hmacAuditLogger->countEntries(),
            'hmac_policy' => $this->hmacPolicyService->getStatus(),
        ]);

        $response->getBody()->write($html);
        return $response;
    }
}
