<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Service\LogService;
use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\SensorReadRepository;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\StatisticsAggregatorService;
use App\Service\TemplateRenderer;
use App\Service\WaterBalanceService;
use App\Util\DurationFormatter;
use App\Util\RealtimeUrlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AquaponieController
{
    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private StatisticsAggregatorService $statsAggregator,
        private ChartDataService $chartDataService,
        private WaterBalanceService $waterBalanceService,
        private TemplateRenderer $renderer,
        private LogService $logger,
        private DateRangeExtractor $dateRangeExtractor,
        private CsvExportService $csvExportService,
    ) {
    }

    /**
     * Affiche la page suivi « vue classique » (servie à /aquaponie-alt).
     */
    public function show(Request $request, Response $response): Response
    {
        try {
            $data = $this->getAquaponieData($request, $response);
            if ($data instanceof Response) {
                return $data;
            }
            $html = $this->renderer->render('aquaponie.twig', $data);
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'CSRF') !== false) {
                $response->getBody()->write('Token CSRF invalide. Veuillez recharger la page et réessayer.');
                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('AquaponieController::show failure', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $response->getBody()->write("ERREUR AquaponieController: " . $e->getMessage());
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }

    /**
     * Affiche la page suivi principale « vue paysage » (servie à /aquaponie).
     */
    public function showAlt(Request $request, Response $response): Response
    {
        try {
            $data = $this->getAquaponieData($request, $response);
            if ($data instanceof Response) {
                return $data;
            }
            $html = $this->renderer->render('aquaponie_alt.twig', $data);
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'CSRF') !== false) {
                $response->getBody()->write('Token CSRF invalide. Veuillez recharger la page et réessayer.');
                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('AquaponieController::showAlt failure', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $response->getBody()->write("ERREUR AquaponieController: " . $e->getMessage());
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }

    /**
     * Construit les données communes pour les pages suivi (vue classique et vue paysage).
     */
    private function getAquaponieData(Request $request, Response $response): array|Response
    {
        $lastDate = $this->sensorReadRepo->getLastReadingDate();
        $defaultEndDate = $lastDate ?: date('Y-m-d H:i:s');
        $defaultStartDate = date('Y-m-d H:i:s', strtotime($defaultEndDate . ' -6 hours'));

        [$startDate, $endDate] = $this->dateRangeExtractor->extract($request, $defaultStartDate, $defaultEndDate);

        $readings = $this->sensorReadRepo->fetchBetween($startDate, $endDate);
        $measure_count = count($readings);

        $chartSeries = $this->chartDataService->prepareSeriesData($readings);
        $reading_time = $this->chartDataService->prepareTimestamps($readings);

        $lastReading = $this->sensorReadRepo->getLastReadings();
        $lastReadingExtracted = $this->chartDataService->extractLastReadings($lastReading);

        $allStats = $this->statsAggregator->aggregateAllStats($startDate, $endDate);
        $statsFlattened = $this->statsAggregator->flattenForLegacy($allStats);

        $body = $request->getParsedBody() ?? [];
        if (isset($body['export_csv'])) {
            return $this->csvExportService->export(
                $this->sensorReadRepo, $startDate, $endDate, $response, 'sensor_data'
            );
        }

        $queryParams = $request->getQueryParams();
        if (isset($queryParams['legacy'])) {
            return $response->withStatus(302)->withHeader('Location', '/aquaponie');
        }

        $firmwareVersion = $this->sensorReadRepo->getFirmwareVersion();
        $waterBalance = $this->waterBalanceService->computeBalance($startDate, $endDate);
        $environment = TableConfig::getEnvironment();
        $dataTable = TableConfig::getDataTable();
        $realtime_api_base = RealtimeUrlHelper::getRealtimeApiBase($environment);

        return array_merge([
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'reading_time' => $reading_time,
            'measure_count' => $measure_count,
            'duration_str' => DurationFormatter::short($startDate, $endDate),
            'version' => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment' => $environment,
            'data_table' => $dataTable,
            'realtime_api_base' => $realtime_api_base,
            'nav_active' => 'aquaponie',
        ], $chartSeries, [
            'last_reading_tempair' => $lastReadingExtracted['tempair'],
            'last_reading_tempeau' => $lastReadingExtracted['tempeau'],
            'last_reading_humi' => $lastReadingExtracted['humi'],
            'last_reading_lumi' => $lastReadingExtracted['lumi'],
            'last_reading_eauaqua' => $lastReadingExtracted['eauaqua'],
            'last_reading_eaureserve' => $lastReadingExtracted['eaureserve'],
            'last_reading_eaupota' => $lastReadingExtracted['eaupota'],
        ], $statsFlattened, $waterBalance);
    }

    /**
     * Affiche la page Caractéristiques du module FFP3.
     */
    public function showDescription(Request $request, Response $response): Response
    {
        $html = $this->renderer->render('aquaponie_description.twig', [
            'page_title' => 'Caractéristiques du module FFP3 - n3 iot datas',
            'images_base' => '/ffp3/assets/images/aquaponie-description',
            'nav_active' => 'aquaponie',
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
