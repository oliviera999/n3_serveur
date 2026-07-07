<?php

declare(strict_types=1);

namespace App\Controller\Pgl;

use App\Controller\Concerns\PglHmacAuthTrait;
use App\Repository\PglRepository;
use App\Service\HmacAuditLogger;
use App\Service\HmacPolicyService;
use App\Service\LogService;
use App\Service\OperationalSettingsService;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Heartbeat firmware Poissonglouton — POST /pgl/heartbeat
 *
 * Contrat aligné MSP/N3PP : uptime, free, min, reboots (+ sensor, version, rssi optionnels).
 * Auth : signature HMAC-SHA256 (contrat FFP3/N3PP/MSP) avec repli api_key.
 */
final class PglHeartbeatController
{
    use PglHmacAuthTrait;

    public function __construct(
        private LogService $logger,
        ?HmacAuditLogger $hmacAuditLogger,
        ?HmacPolicyService $hmacPolicyService,
        ?OperationalSettingsService $operationalSettings,
        private PglRepository $repository,
    ) {
        $this->hmacAuditLogger = $hmacAuditLogger;
        $this->hmacPolicyService = $hmacPolicyService;
        $this->operationalSettings = $operationalSettings;
    }

    protected function componentName(): string
    {
        return 'PglHeartbeat';
    }

    public function handle(Request $request, Response $response): Response
    {
        if ($request->getMethod() !== 'POST') {
            return ResponseHelper::text($response, 'POST requis', 405);
        }

        $params = RequestHelper::extractParams($request);
        if ($params === []) {
            return ResponseHelper::text($response, 'Donnees manquantes', 400);
        }

        // Auth : signature HMAC-SHA256 (contrat FFP3/N3PP/MSP) prioritaire,
        // repli sur api_key legacy si le firmware n'envoie pas de signature.
        $this->authenticatedByHmac = false;
        $params = $this->prepareHmacParams($request, $params);
        $authError = $this->validateHmacOrFallback($params, $response);
        if ($authError !== null) {
            return $authError;
        }
        if ($this->requiresApiKey()) {
            $apiKey = isset($params['api_key']) ? trim((string) $params['api_key']) : '';
            $expected = (string) ($_ENV['PGL_API_KEY'] ?? $_ENV['API_KEY'] ?? '');
            if ($expected === '' || !hash_equals($expected, $apiKey)) {
                $this->logger->warning('PglHeartbeat: cle API invalide', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'n/a',
                ]);
                return ResponseHelper::text($response, 'API key invalide', 401);
            }
        }

        $get = static fn (string $k): string => isset($params[$k]) && is_scalar($params[$k])
            ? trim((string) $params[$k]) : '';

        $uptime = $this->sanitizeNumeric($get('uptime'));
        $free = $this->sanitizeNumeric($get('free'));
        $min = $this->sanitizeNumeric($get('min'));
        $reboots = $this->sanitizeNumeric($get('reboots'));

        if ($uptime === '' || $free === '' || $min === '' || $reboots === '') {
            return ResponseHelper::text($response, 'Champs manquants', 400);
        }

        $sensor = substr($get('sensor'), 0, 30) ?: null;
        $version = substr($get('version'), 0, 30) ?: null;
        $rssi = $get('rssi') !== '' ? (int) $get('rssi') : null;
        $sensorsPresent = $get('sensors_present') !== '' ? (int) $get('sensors_present') : null;

        try {
            $this->repository->insertHeartbeat(
                (int) $uptime,
                (int) $free,
                (int) $min,
                (int) $reboots,
                $rssi,
                $sensor,
                $version,
                $sensorsPresent
            );

            $this->logger->info('PglHeartbeat OK', [
                'sensor' => $sensor,
                'version' => $version,
                'uptime' => $uptime,
                'reboots' => $reboots,
                'sensors_present' => $sensorsPresent,
            ]);

            return ResponseHelper::textClose($response, 'OK', 200);
        } catch (\Throwable $e) {
            $this->logger->error('PglHeartbeat: erreur insertion', ['msg' => $e->getMessage()]);
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }

    private function sanitizeNumeric(string $data): string
    {
        $filtered = filter_var(trim($data), FILTER_SANITIZE_NUMBER_INT);
        return $filtered !== false ? $filtered : '';
    }
}
