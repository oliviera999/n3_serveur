<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\SensorReadRepository;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\SensorStatisticsService;
use App\Service\TemplateRenderer;
use App\Util\DurationFormatter;
use App\Util\RealtimeUrlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private SensorStatisticsService $statsService,
        private TemplateRenderer $renderer,
        private DateRangeExtractor $dateRangeExtractor,
        private CsvExportService $csvExportService,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $lastDate = $this->sensorReadRepo->getLastReadingDate();
        $defaultEndDate = $lastDate ?: date('Y-m-d H:i:s');
        $defaultStartDate = date('Y-m-d H:i:s', strtotime($defaultEndDate . ' -1 day'));

        [$startDate, $endDate] = $this->dateRangeExtractor->extract(
            $request, $defaultStartDate, $defaultEndDate, validateCsrf: false
        );

        $body = $request->getParsedBody() ?? [];
        if (isset($body['export_csv'])) {
            return $this->csvExportService->export(
                $this->sensorReadRepo, $startDate, $endDate, $response, 'sensor_data'
            );
        }

        $readings = $this->sensorReadRepo->fetchBetween($startDate, $endDate);
        $readingsCount = count($readings);
        $lastReading = $readings[0] ?? null;

        $stats = [
            'TempAir' => $this->calculateStats('TempAir', $startDate, $endDate),
            'TempEau' => $this->calculateStats('TempEau', $startDate, $endDate),
            'Humidite' => $this->calculateStats('Humidite', $startDate, $endDate),
            'Luminosite' => $this->calculateStats('Luminosite', $startDate, $endDate),
            'EauAquarium' => $this->calculateStats('EauAquarium', $startDate, $endDate),
            'EauReserve' => $this->calculateStats('EauReserve', $startDate, $endDate),
            'EauPotager' => $this->calculateStats('EauPotager', $startDate, $endDate),
        ];

        $firmwareVersion = $this->sensorReadRepo->getFirmwareVersion();
        $environment = TableConfig::getEnvironment();
        $realtime_api_base = RealtimeUrlHelper::getRealtimeApiBase($environment);

        $html = $this->renderer->render('dashboard.twig', [
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'duration'      => DurationFormatter::long($startDate, $endDate),
            'readingsCount' => $readingsCount,
            'lastReading'   => $lastReading,
            'stats'         => $stats,
            'version'       => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment'   => $environment,
            'realtime_api_base' => $realtime_api_base,
            'nav_active' => 'aquaponie',
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function calculateStats(string $column, string $start, string $end): array
    {
        return [
            'min' => $this->statsService->min($column, $start, $end),
            'max' => $this->statsService->max($column, $start, $end),
            'avg' => $this->statsService->avg($column, $start, $end),
            'stddev' => $this->statsService->stddev($column, $start, $end),
        ];
    }
}
