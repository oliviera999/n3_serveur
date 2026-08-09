<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\FirmwareStateCompat;
use App\Service\OperationalSettingsService;
use App\Service\Realtime\AbstractSensorRealtimeDataProvider;
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
    protected FirmwareStateCompat $firmwareStateCompat;

    public function __construct(
        protected RealtimeDataProviderInterface $provider,
        ?OperationalSettingsService $operationalSettings = null,
    ) {
        $this->firmwareStateCompat = new FirmwareStateCompat($operationalSettings);
    }

    /**
     * Module firmware servi par ce contrôleur, pour la couche de compatibilité
     * ({@see FirmwareStateCompat}). '' = famille sans contrat de clés plates (FFP3).
     */
    protected function firmwareModule(): string
    {
        return '';
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
            // Audit C1/C2 : si un firmware appelle /api/outputs/state (format nested)
            // avec X-Api-Key, acquitter les one-shots ET récupérer l'état PLAT
            // {gpio: state}. Le polling UI n'envoie pas la clé → null, réponse nested
            // inchangée (pas d'ack prématuré, pas de clés plates).
            //
            // Compatibilité flotte déployée : la fusion des clés plates est pilotée par
            // FIRMWARE_FLAT_STATE_MODE ({@see FirmwareStateCompat}).
            //
            // Mode `off` (défaut) : AUCUNE clé plate n'est renvoyée (la flotte non
            // reflashable garde sa config locale). Corrigé en 6.37.3 : on n'acquitte
            // PLUS les one-shots dans ce cas. Avant, l'ack tournait quand même alors
            // que la réponse restait nested-only → GPIO 110 (reset) / 13 (arrosage
            // manuel n3pp) étaient consommés sans jamais être vus par le firmware
            // déployé (URL `/api/outputs/state` + X-Api-Key). Les commandes restent
            // en attente jusqu'au passage en `safe`/`full`, ou jusqu'à un GET sur la
            // route dédiée `/api/firmware/outputs/state` (toujours plate + ack).
            $payload = [
                'timestamp' => time(),
                'outputs' => $data,
            ];
            $firmwareFlat = null;
            if ($this->firmwareStateCompat->mergesFlatKeys()) {
                $firmwareFlat = $this->maybeAcknowledgeFirmwareOneShots($request);
            }
            if ($firmwareFlat !== null) {
                $firmwareFlat = $this->firmwareStateCompat->sanitize($firmwareFlat, $this->firmwareModule());
                // Clés plates au niveau racine, pour les firmwares n3pp/msp qui lisent
                // myObject["110"], ["106"]… sans écraser 'timestamp'/'outputs'.
                foreach ($firmwareFlat as $gpio => $state) {
                    if ($gpio !== 'timestamp' && $gpio !== 'outputs') {
                        $payload[$gpio] = $state;
                    }
                }
            }
            return ResponseHelper::json($response, $payload);
        } catch (\Throwable $e) {
            error_log('[realtime] ' . $e->getMessage());
            return ResponseHelper::json($response, ['error' => 'Erreur serveur'], 500);
        }
    }

    /**
     * Ack one-shot + état plat firmware, uniquement pour un client firmware
     * authentifié par API key. Retourne l'état plat {gpio: state} à fusionner dans
     * la réponse, ou null si la requête n'est pas un firmware authentifié (polling UI).
     *
     * @return array<string, string>|null
     */
    private function maybeAcknowledgeFirmwareOneShots(Request $request): ?array
    {
        if (!$this->provider instanceof AbstractSensorRealtimeDataProvider) {
            return null;
        }

        $expected = $_ENV['API_KEY'] ?? '';
        if (!is_string($expected) || $expected === '') {
            return null;
        }

        $provided = $request->getHeaderLine('X-Api-Key');
        if ($provided === '') {
            $qp = $request->getQueryParams();
            $provided = isset($qp['api_key']) ? trim((string) $qp['api_key']) : '';
        }

        if ($provided === '' || !hash_equals($expected, $provided)) {
            return null;
        }

        return $this->provider->acknowledgeFirmwareOneShots();
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
