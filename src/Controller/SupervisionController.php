<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\Version;
use App\Repository\IotDeviceRepository;
use App\Repository\NavPageRepository;
use App\Service\Contract\ContractAlignmentService;
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
        private ContractAlignmentService $contractAlignment,
        private IotDeviceRepository $deviceRepository,
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

        $alignment = [];
        $alignmentSummary = ['ok' => 0, 'warn' => 0, 'error' => 0];
        try {
            $alignment = $this->contractAlignment->checkAll();
            foreach ($alignment as $row) {
                $status = (string) ($row['status'] ?? 'ok');
                if (isset($alignmentSummary[$status])) {
                    $alignmentSummary[$status]++;
                }
            }
        } catch (\Throwable) {
            $alignment = [];
        }

        $devices = [];
        $liveDevices = [];
        try {
            $devices = $this->deviceRepository->findAll(false);
            $liveDevices = $this->deviceRepository->findLiveDevices();
        } catch (\Throwable) {
            $devices = [];
            $liveDevices = [];
        }

        $html = $this->renderer->render('supervision.twig', [
            'page_title' => 'Supervision - n3 iot datas',
            'nav_active' => 'supervision',
            'version' => Version::getWithPrefix(),
            'nav_states' => $navStates,
            'hmac_audit_enabled' => $this->hmacAuditLogger->isEnabled(),
            'hmac_audit_entry_count' => $this->hmacAuditLogger->countEntries(),
            'hmac_policy' => $this->hmacPolicyService->getStatus(),
            'contract_alignment' => $alignment,
            'contract_alignment_summary' => $alignmentSummary,
            'iot_devices' => $devices,
            'iot_live_devices' => $liveDevices,
            'iot_families' => IotDeviceRepository::FAMILIES,
        ]);

        $response->getBody()->write($html);

        return $response;
    }
}
