<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\RealtimeDataService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur API pour les données temps réel
 * Fournit des endpoints JSON pour le polling côté client
 */
class RealtimeApiController
{
    public function __construct(
        private RealtimeDataService $realtimeService
    ) {
    }

    /**
     * GET /api/realtime/sensors/latest
     * Retourne les dernières lectures de tous les capteurs
     */
    public function getLatestSensors(Request $request, Response $response): Response
    {
        try {
            $data = $this->realtimeService->getLatestReadings();

            return ResponseHelper::json($response, $data);
        } catch (\Throwable $e) {
            return ResponseHelper::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/realtime/sensors/since/{timestamp}
     * Retourne les nouvelles lectures depuis un timestamp Unix donné
     */
    public function getSensorsSince(Request $request, Response $response, array $args): Response
    {
        $timestamp = (int)($args['timestamp'] ?? 0);
        
        if ($timestamp <= 0) {
            return ResponseHelper::error($response, 'Invalid timestamp', 400);
        }

        $data = $this->realtimeService->getReadingsSince($timestamp);
        
        return ResponseHelper::json($response, [
            'count' => count($data),
            'readings' => $data,
        ]);
    }

    /**
     * GET /api/realtime/outputs/state
     * Retourne l'état actuel de tous les GPIO/outputs
     */
    public function getOutputsState(Request $request, Response $response): Response
    {
        try {
            $data = $this->realtimeService->getOutputsState();

            return ResponseHelper::json($response, [
                'timestamp' => time(),
                'outputs' => $data,
            ]);
        } catch (\Throwable $e) {
            return ResponseHelper::error($response, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/realtime/system/health
     * Retourne le statut de santé du système
     */
    public function getSystemHealth(Request $request, Response $response): Response
    {
        $health = $this->realtimeService->getSystemHealth();
        
        return ResponseHelper::json($response, $health);
    }

    /**
     * GET /api/realtime/alerts/active
     * Retourne la liste des alertes actives
     */
    public function getActiveAlerts(Request $request, Response $response): Response
    {
        $alerts = $this->realtimeService->getActiveAlerts();
        
        return ResponseHelper::json($response, [
            'timestamp' => time(),
            'count' => count($alerts),
            'alerts' => $alerts,
        ]);
    }
}
