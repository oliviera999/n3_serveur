<?php

declare(strict_types=1);

namespace App\Controller\Pgl;

use App\Config\PglConfig;
use App\Repository\PglRepository;
use App\Service\TemplateRenderer;
use App\Util\DurationFormatter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PglStatsController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private PglRepository $repository,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $showOnline = PglConfig::SHOW_ONLINE_STATUS_ON_PAGE && PglConfig::ONLINE_CHECK_ENABLED;

        $nowTs = time();
        $endHourStartTs = $nowTs - ($nowTs % 3600);
        $startHourStartTs = $endHourStartTs - (72 - 1) * 3600;

        $startDatetime = date('Y-m-d H:i:s', $startHourStartTs);
        $endDatetime = date('Y-m-d H:i:s', $endHourStartTs + 3599);

        $startDate = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDatetime);
        $endDate = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endDatetime);

        $readingTimeMs = [];
        $countDeltaSeries = [];
        $totalCountSeries = [];
        $measureCount = 0;
        $sensorsConfig = [];
        $realtimeApiBase = '/pgl/api/realtime';

        // Résilience : si les tables PGL n'existent pas encore en base (module récent,
        // migration CREATE_PGL_TABLES non appliquée), on rend la page avec des valeurs
        // neutres plutôt que de laisser remonter une PDOException (erreur 500).
        try {
            $events = $this->repository->getEventsBetween($startDatetime, $endDatetime);
            $measureCount = $this->repository->countEventsBetween($startDatetime, $endDatetime);

            // Initialisation de 72 buckets horaires (même s'il n'y a pas d'événements).
            $buckets = [];
            for ($ts = $startHourStartTs; $ts <= $endHourStartTs; $ts += 3600) {
                $buckets[$ts] = [
                    'total' => 0,
                    'battery_v' => null,
                    'rssi' => null,
                    'last_event_time' => null,
                ];
            }

            foreach ($events as $event) {
                $eventTimeStr = (string) ($event['event_time'] ?? '');
                $eventTs = strtotime($eventTimeStr);
                if ($eventTs === false) {
                    continue;
                }

                $bucketStartTs = $eventTs - ($eventTs % 3600);
                if (!isset($buckets[$bucketStartTs])) {
                    continue;
                }

                $buckets[$bucketStartTs]['total'] += (int) ($event['count_delta'] ?? 0);
                if (array_key_exists('battery_v', $event) && $event['battery_v'] !== null) {
                    $buckets[$bucketStartTs]['battery_v'] = (float) $event['battery_v'];
                }
                if (array_key_exists('rssi', $event) && $event['rssi'] !== null) {
                    $buckets[$bucketStartTs]['rssi'] = (int) $event['rssi'];
                }
                $buckets[$bucketStartTs]['last_event_time'] = $eventTimeStr;
            }

            // Série “cumul absolu” (tient compte des événements avant la fenêtre).
            $runningBefore = $this->repository->getRunningTotalBefore($startDatetime);
            $cumulative = $runningBefore;

            for ($ts = $startHourStartTs; $ts <= $endHourStartTs; $ts += 3600) {
                $bucketTotal = (int) $buckets[$ts]['total'];
                $cumulative += $bucketTotal;

                $readingTimeMs[] = $ts * 1000;
                $countDeltaSeries[] = $bucketTotal;
                $totalCountSeries[] = $cumulative;
            }

            $latestEvent = $this->repository->getLatestEvent();
            $latestBatteryV = $latestEvent['battery_v'] ?? null;
            $latestRssi = $latestEvent['rssi'] ?? null;

            // Dernier bucket horaire “dans la fenêtre” pour count_delta affiché.
            $latestCountDelta = (int) ($buckets[$endHourStartTs]['total'] ?? 0);
            if ($latestEvent !== null && isset($latestEvent['event_time'])) {
                $latestEventTs = strtotime((string) $latestEvent['event_time']);
                if ($latestEventTs !== false) {
                    $latestBucketTs = $latestEventTs - ($latestEventTs % 3600);
                    if (isset($buckets[$latestBucketTs])) {
                        $latestCountDelta = (int) $buckets[$latestBucketTs]['total'];
                    }
                }
            }

            $latestTotalCount = !empty($totalCountSeries)
                ? end($totalCountSeries)
                : $this->repository->getTotalCount();

            $sensorsConfig = [
                [
                    'key' => 'total_count',
                    'label' => 'Total recyclé',
                    'icon' => 'fa-recycle',
                    'type' => 'system',
                    'unit' => '',
                    'decimals' => 0,
                    'value' => $latestTotalCount,
                ],
                [
                    'key' => 'count_delta',
                    'label' => 'Dernier lot (heure)',
                    'icon' => 'fa-layer-group',
                    'type' => 'system',
                    'unit' => '',
                    'decimals' => 0,
                    'value' => $latestCountDelta,
                ],
            ];

            if ($latestBatteryV !== null) {
                $sensorsConfig[] = [
                    'key' => 'battery_v',
                    'label' => 'Batterie',
                    'icon' => 'fa-battery-half',
                    'type' => 'system',
                    'unit' => 'V',
                    'decimals' => 2,
                    'value' => $latestBatteryV,
                ];
            }

            if ($latestRssi !== null) {
                $sensorsConfig[] = [
                    'key' => 'rssi',
                    'label' => 'Signal WiFi',
                    'icon' => 'fa-signal',
                    'type' => 'system',
                    'unit' => 'dBm',
                    'decimals' => 0,
                    'value' => $latestRssi,
                ];
            }
        } catch (\Throwable) {
            // Valeurs neutres si la BDD n'est pas prête.
            $measureCount = 0;
            for ($ts = $startHourStartTs; $ts <= $endHourStartTs; $ts += 3600) {
                $readingTimeMs[] = $ts * 1000;
                $countDeltaSeries[] = 0;
                $totalCountSeries[] = 0;
            }
            $sensorsConfig = [
                [
                    'key' => 'total_count',
                    'label' => 'Total recyclé',
                    'icon' => 'fa-recycle',
                    'type' => 'system',
                    'unit' => '',
                    'decimals' => 0,
                    'value' => 0,
                ],
                [
                    'key' => 'count_delta',
                    'label' => 'Dernier lot (heure)',
                    'icon' => 'fa-layer-group',
                    'type' => 'system',
                    'unit' => '',
                    'decimals' => 0,
                    'value' => 0,
                ],
            ];
        }

        $html = $this->renderer->render('pgl_stats.twig', [
            'page_title' => 'Poissonglouton - Données',
            'nav_active' => 'pgl',
            'active_page' => 'pgl',
            'environment' => $_ENV['ENV'] ?? 'prod',
            'show_online_status' => $showOnline,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'duration_str' => DurationFormatter::short($startDatetime, $endDatetime),
            'measure_count' => $measureCount,
            'sensors_config' => $sensorsConfig,
            'reading_time' => $readingTimeMs,
            'count_delta_series' => $countDeltaSeries,
            'total_count_series' => $totalCountSeries,
            'chart_ids_json' => json_encode(['chart-hourly', 'chart-cumulative']),
            'sensor_map_json' => json_encode([
                'count_delta' => ['chartId' => 'chart-hourly', 'seriesIndex' => 0],
                'total_count' => ['chartId' => 'chart-cumulative', 'seriesIndex' => 0],
            ]),
            'realtime_api_base' => $realtimeApiBase,
        ]);

        $response->getBody()->write($html);
        return $response;
    }
}
