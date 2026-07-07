<?php

declare(strict_types=1);

namespace App\Controller\Pgl;

use App\Controller\Concerns\PglHmacAuthTrait;
use App\Repository\PglRepository;
use App\Service\HmacAuditLogger;
use App\Service\HmacPolicyService;
use App\Service\LogService;
use App\Service\OperationalSettingsService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PglPostDataController
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
        return 'PglPostData';
    }

    /** Bitmask firmware PGL_SENS_* (1=IR, 2=US, 4=PIR) ou legacy 1/2/3. */
    private function modeBitmaskToLabel(string $raw): string
    {
        if (!ctype_digit($raw)) {
            return 'unknown';
        }
        $mask = (int) $raw;
        if ($mask === 0) {
            return 'none';
        }
        if ($mask === 3) {
            return 'tandem';
        }
        $parts = [];
        if (($mask & 1) !== 0) {
            $parts[] = 'ir';
        }
        if (($mask & 2) !== 0) {
            $parts[] = 'us';
        }
        if (($mask & 4) !== 0) {
            $parts[] = 'pir';
        }

        return $parts !== [] ? implode('_', $parts) : 'unknown';
    }

    public function handle(Request $request, Response $response): Response
    {
        $params = (array) ($request->getParsedBody() ?? []);

        // Auth : signature HMAC-SHA256 (contrat FFP3/N3PP/MSP) prioritaire,
        // repli sur api_key legacy si le firmware n'envoie pas de signature.
        $this->authenticatedByHmac = false;
        $params = $this->prepareHmacParams($request, $params);
        $authError = $this->validateHmacOrFallback($params, $response);
        if ($authError !== null) {
            return $authError;
        }
        if ($this->requiresApiKey()) {
            $apiKey = (string) ($params['api_key'] ?? '');
            $expected = (string) ($_ENV['PGL_API_KEY'] ?? $_ENV['API_KEY'] ?? '');
            if ($expected === '' || !hash_equals($expected, $apiKey)) {
                return ResponseHelper::text($response, 'API key invalide', 401);
            }
        }

        $sensor = (string) ($params['sensor'] ?? 'poissonglouton');
        $version = substr((string) ($params['version'] ?? '0.0.0'), 0, 30);
        $eventsRaw = (string) ($params['events'] ?? '');
        if ($eventsRaw === '') {
            return ResponseHelper::text($response, 'Champ events manquant', 400);
        }

        $lines = array_filter(explode(',', $eventsRaw), static fn (string $chunk): bool => trim($chunk) !== '');
        if ($lines === []) {
            return ResponseHelper::text($response, 'Aucun evenement valide', 400);
        }

        $inserted = 0;
        $skipped = 0;
        $lastAckedEventId = 0;

        foreach ($lines as $line) {
            $parts = explode(':', trim($line));
            // Format : epoch:countDelta:sensorMode:tandemValidated:batteryMv:rssi[:eventId]
            if (count($parts) < 6) {
                continue;
            }

            $ts = ctype_digit($parts[0]) ? (int) $parts[0] : 0;
            $countDelta = ctype_digit($parts[1]) ? (int) $parts[1] : 0;
            $mode = $this->modeBitmaskToLabel($parts[2]);
            $tandem = ((int) $parts[3]) === 1;
            $batteryMv = (int) $parts[4];
            $rssi = (int) $parts[5];
            // Champ optionnel eventId (firmwares >= offline-sync)
            $deviceEventId = isset($parts[6]) && ctype_digit($parts[6]) ? (int) $parts[6] : 0;

            if ($ts <= 0 || $countDelta <= 0) {
                continue;
            }

            $eventTime = date('Y-m-d H:i:s', $ts);
            $batteryV = $batteryMv > 0 ? ((float) $batteryMv / 1000.0) : null;

            if ($deviceEventId > 0) {
                // Mode idempotent : INSERT IGNORE sur (board, device_event_id)
                $wasInserted = $this->repository->insertEventIdempotent(
                    $sensor,
                    $eventTime,
                    $countDelta,
                    $mode,
                    $tandem,
                    $batteryV,
                    $rssi,
                    $version,
                    $deviceEventId
                );
                if ($wasInserted) {
                    $inserted++;
                } else {
                    $skipped++;
                }
                // On acquitte jusqu'au plus grand eventId reçu, qu'il soit nouveau ou doublon
                if ($deviceEventId > $lastAckedEventId) {
                    $lastAckedEventId = $deviceEventId;
                }
            } else {
                // Ancien firmware sans event_id : insertion classique (pas d'idempotence)
                $this->repository->insertEvent(
                    $sensor,
                    $eventTime,
                    $countDelta,
                    $mode,
                    $tandem,
                    $batteryV,
                    $rssi,
                    $version
                );
                $inserted++;
            }
        }

        $this->logger->info(
            'PGL post-data OK sensor={sensor} inserted={inserted} skipped={skipped}',
            [
                'sensor' => $sensor,
                'inserted' => $inserted,
                'skipped' => $skipped,
            ]
        );

        if ($inserted === 0 && $skipped === 0) {
            return ResponseHelper::text($response, 'Aucune ligne inseree', 400);
        }

        $responseData = [
            'status' => 'ok',
            'inserted' => $inserted,
        ];
        if ($lastAckedEventId > 0) {
            $responseData['last_acked_event_id'] = $lastAckedEventId;
        }

        return ResponseHelper::json($response, $responseData);
    }
}
