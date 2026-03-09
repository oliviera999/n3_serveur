<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Realtime\RealtimeDataProviderInterface;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur abstrait pour les API temps réel.
 * Chaque module (FFP3, MSP1, N3PP) hérite de cette classe
 * et injecte son propre RealtimeDataProviderInterface.
 *
 * Format de réponse aligné sur le contrat FFP3 (référence).
 */
abstract class AbstractRealtimeApiController
{
    public function __construct(
        protected RealtimeDataProviderInterface $provider
    ) {
    }

    public function getLatestSensors(Request $request, Response $response): Response
    {
        try {
            $data = $this->provider->getLatestReadings();
            return ResponseHelper::json($response, $data);
        } catch (\Throwable $e) {
            return ResponseHelper::error($response, $e->getMessage(), 500);
        }
    }

    public function getSensorsSince(Request $request, Response $response, array $args): Response
    {
        $timestamp = (int) ($args['timestamp'] ?? 0);

        if ($timestamp <= 0) {
            return ResponseHelper::error($response, 'Invalid timestamp', 400);
        }

        $data = $this->provider->getReadingsSince($timestamp);

        return ResponseHelper::json($response, [
            'count' => count($data),
            'readings' => $data,
        ]);
    }

    public function getOutputsState(Request $request, Response $response): Response
    {
        try {
            $data = $this->provider->getOutputsState();
            return ResponseHelper::json($response, [
                'timestamp' => time(),
                'outputs' => $data,
            ]);
        } catch (\Throwable $e) {
            return ResponseHelper::error($response, $e->getMessage(), 500);
        }
    }

    public function getSystemHealth(Request $request, Response $response): Response
    {
        $health = $this->provider->getSystemHealth();
        return ResponseHelper::json($response, $health);
    }

    public function getActiveAlerts(Request $request, Response $response): Response
    {
        $alerts = $this->provider->getActiveAlerts();
        return ResponseHelper::json($response, [
            'timestamp' => time(),
            'count' => count($alerts),
            'alerts' => $alerts,
        ]);
    }
}
