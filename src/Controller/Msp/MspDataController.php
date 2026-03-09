<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\MspSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\TemplateRenderer;
use App\Util\DurationFormatter;
use App\Util\RealtimeUrlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MspDataController
{
    private const BOARD = 2;

    private const CHART_COLUMNS = [
        'TempAirInt', 'TempAirExt',
        'HumidAirInt', 'HumidAirExt',
        'LuminositeMoy', 'LuminositeA', 'LuminositeB', 'LuminositeC', 'LuminositeD',
        'HumidSol', 'TempEau', 'Pluie',
        'PontDiv', 'bootCount',
        'ServoHB', 'ServoGD', 'resetMode',
    ];

    private const STATS_COLUMNS = [
        'TempAirInt', 'TempAirExt', 'HumidAirInt', 'HumidAirExt',
        'LuminositeMoy', 'LuminositeA', 'LuminositeB', 'LuminositeC', 'LuminositeD',
        'HumidSol', 'TempEau', 'Pluie', 'PontDiv', 'bootCount',
    ];

    public function __construct(
        private TemplateRenderer $renderer,
        private MspSensorRepository $sensorRepo,
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
                $this->sensorRepo, $startDate, $endDate, $response, 'msp1_data'
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
        $testSuffix = $env === 'msp_test' ? ' (TEST)' : '';

        $html = $this->renderer->render('msp1_data.twig', array_merge([
            'page_title' => 'Données station météo - Le potager' . $testSuffix,
            'nav_active' => 'potager',
            'latest' => $latest,
            'board' => self::BOARD,
            'version' => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment' => $env,
            'realtime_api_base' => RealtimeUrlHelper::getMspRealtimeApiBase($env),
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
