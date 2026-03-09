<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\N3ppSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\TemplateRenderer;
use App\Util\DurationFormatter;
use App\Util\RealtimeUrlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class N3ppDataController
{
    private const BOARD = 3;

    private const CHART_COLUMNS = [
        'TempAir', 'Humidite', 'Luminosite',
        'Humid1', 'Humid2', 'Humid3', 'Humid4', 'HumidMoy',
        'PontDiv', 'bootCount',
        'etatPompe', 'resetMode',
    ];

    private const STATS_COLUMNS = [
        'TempAir', 'Humidite', 'Luminosite',
        'Humid1', 'Humid2', 'Humid3', 'Humid4', 'HumidMoy',
        'PontDiv', 'bootCount', 'etatPompe',
    ];

    public function __construct(
        private TemplateRenderer $renderer,
        private N3ppSensorRepository $sensorRepo,
        private CsrfService $csrfService,
        private DateRangeExtractor $dateRangeExtractor,
        private CsvExportService $csvExportService,
        private ChartDataService $chartDataService,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $lastDate = $this->sensorRepo->getLastReadingDate();
        $defaultEnd = $lastDate ?: date('Y-m-d H:i:s');
        $defaultStart = date('Y-m-d H:i:s', strtotime($defaultEnd . ' -24 hours'));

        [$startDate, $endDate] = $this->dateRangeExtractor->extract($request, $defaultStart, $defaultEnd);

        $body = $request->getParsedBody() ?? [];
        if (isset($body['export_csv'])) {
            return $this->csvExportService->export(
                $this->sensorRepo, $startDate, $endDate, $response, 'n3pp_data'
            );
        }

        $readings = $this->sensorRepo->fetchBetween($startDate, $endDate);
        $measureCount = count($readings);

        $chartData = $this->chartDataService->prepareGenericSeries($readings, self::CHART_COLUMNS);
        $latest = $this->sensorRepo->getLatest();
        $firmwareVersion = $this->sensorRepo->getFirmwareVersion();

        $stats = [];
        foreach (self::STATS_COLUMNS as $col) {
            $s = $this->sensorRepo->getColumnStats($col, $startDate, $endDate);
            $lc = lcfirst($col);
            $stats["avg_$lc"] = $s['avg'];
            $stats["min_$lc"] = $s['min'];
            $stats["max_$lc"] = $s['max'];
            $stats["stddev_$lc"] = $s['stddev'];
        }

        $env = TableConfig::getEnvironment();
        $testSuffix = $env === 'n3pp_test' ? ' (TEST)' : '';

        $html = $this->renderer->render('n3pp_data.twig', array_merge([
            'page_title' => 'Données serre / élevage - n3 iot' . $testSuffix,
            'nav_active' => 'elevage',
            'latest' => $latest,
            'board' => self::BOARD,
            'version' => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment' => $env,
            'realtime_api_base' => RealtimeUrlHelper::getN3ppRealtimeApiBase($env),
            'csrf_field' => $this->csrfService->getHiddenField(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'measure_count' => $measureCount,
            'duration_str' => DurationFormatter::short($startDate, $endDate),
        ], $chartData, $stats));

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
