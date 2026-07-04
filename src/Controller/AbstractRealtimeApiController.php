<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Realtime\RealtimeDataProviderInterface;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur abstrait pour les APIs temps réel.
 * Délègue à un RealtimeDataProviderInterface (FFP3, MSP1 ou N3PP).
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
            error_log('[realtime] ' . $e->getMessage());
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    public function getSensorsSince(Request $request, Response $response, array $args = []): Response
    {
        try {
            $routeArgs = $request->getAttribute('__route__')?->getArguments() ?? $args;
            $timestamp = (int) ($routeArgs['timestamp'] ?? 0);
            if ($timestamp <= 0) {
                return ResponseHelper::json($response, ['error' => 'Timestamp invalide'], 400);
            }

            $data = $this->provider->getReadingsSince($timestamp);
            return ResponseHelper::json($response, [
                'count' => count($data),
                'readings' => $data,
            ]);
        } catch (\Throwable $e) {
            error_log('[realtime] ' . $e->getMessage());
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    public function getSystemHealth(Request $request, Response $response): Response
    {
        try {
            $data = $this->provider->getSystemHealth();
            return ResponseHelper::json($response, $data);
        } catch (\Throwable $e) {
            error_log('[realtime] ' . $e->getMessage());
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
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
            error_log('[realtime] ' . $e->getMessage());
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    public function getActiveAlerts(Request $request, Response $response): Response
    {
        try {
            $data = $this->provider->getActiveAlerts();
            return ResponseHelper::json($response, [
                'timestamp' => time(),
                'count' => count($data),
                'alerts' => $data,
            ]);
        } catch (\Throwable $e) {
            error_log('[realtime] ' . $e->getMessage());
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }
}
