<?php

declare(strict_types=1);

namespace App\Controller\Pgl;

use App\Repository\PglRepository;
use App\Service\LogService;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PglPostDataController
{
    public function __construct(
        private LogService $logger,
        private PglRepository $repository,
    ) {
    }

    public function handle(Request $request, Response $response): Response
    {
        $params = (array) ($request->getParsedBody() ?? []);
        $apiKey = (string) ($params['api_key'] ?? '');
        $expected = (string) ($_ENV['PGL_API_KEY'] ?? $_ENV['API_KEY'] ?? '');

        if ($expected === '' || !hash_equals($expected, $apiKey)) {
            return ResponseHelper::text($response, 'API key invalide', 401);
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
        foreach ($lines as $line) {
            $parts = explode(':', trim($line));
            if (count($parts) < 6) {
                continue;
            }

            $ts = ctype_digit($parts[0]) ? (int) $parts[0] : 0;
            $countDelta = ctype_digit($parts[1]) ? (int) $parts[1] : 0;
            $mode = match ($parts[2]) {
                '1' => 'ir',
                '2' => 'us',
                '3' => 'tandem',
                default => 'unknown',
            };
            $tandem = ((int) $parts[3]) === 1;
            $batteryMv = (int) $parts[4];
            $rssi = (int) $parts[5];

            if ($ts <= 0 || $countDelta <= 0) {
                continue;
            }

            $eventTime = date('Y-m-d H:i:s', $ts);
            $batteryV = $batteryMv > 0 ? ((float) $batteryMv / 1000.0) : null;

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

        $this->logger->info('PGL post-data OK sensor={sensor} inserted={inserted}', [
            'sensor' => $sensor,
            'inserted' => $inserted,
        ]);

        if ($inserted === 0) {
            return ResponseHelper::text($response, 'Aucune ligne inseree', 400);
        }

        return ResponseHelper::json($response, [
            'status' => 'ok',
            'inserted' => $inserted,
        ]);
    }
}
