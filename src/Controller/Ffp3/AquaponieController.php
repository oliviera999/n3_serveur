<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Config\TableConfig;
use App\Config\Version;
use App\Controller\AbstractDataController;
use App\Display\Catalog\Ffp3Catalog;
use App\Display\PageCatalog;
use App\Repository\SensorReadRepository;
use App\Security\AuthService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\DisplayPresetService;
use App\Service\LogService;
use App\Service\StatisticsAggregatorService;
use App\Service\TemplateRenderer;
use App\Service\TideCycleDetector;
use App\Service\WaterBalanceService;
use App\Util\DurationFormatter;
use App\Util\Ffp3WaterLevelUnit;
use App\Util\RealtimeUrlHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Page de suivi aquaponie FFP3 — pilotée par Ffp3Catalog + presets d'affichage
 * (même contrat que AbstractDataController pour MSP/N3PP/ENERGIE).
 */
class AquaponieController
{
    private PageCatalog $catalog;

    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private StatisticsAggregatorService $statsAggregator,
        private ChartDataService $chartDataService,
        private WaterBalanceService $waterBalanceService,
        private TideCycleDetector $tideCycleDetector,
        private TemplateRenderer $renderer,
        private LogService $logger,
        private DateRangeExtractor $dateRangeExtractor,
        private CsvExportService $csvExportService,
        private DisplayPresetService $displayPresets,
        private AuthService $authService,
    ) {
        $this->catalog = new Ffp3Catalog();
    }

    /**
     * Page principale `/aquaponie` (preset demandé ou défaut selon rôle).
     */
    public function showAlt(Request $request, Response $response): Response
    {
        return $this->renderMonitoring($request, $response, null);
    }

    /**
     * Ancienne URL `/aquaponie-alt` : force le preset « simple » si aucun ?preset=.
     */
    public function show(Request $request, Response $response): Response
    {
        $requested = AbstractDataController::requestedPreset($request);
        return $this->renderMonitoring($request, $response, $requested ?? PageCatalog::PRESET_SIMPLE);
    }

    private function renderMonitoring(Request $request, Response $response, ?string $forcedPreset): Response
    {
        try {
            $data = $this->buildPageContext($request, $response, $forcedPreset);
            if ($data instanceof Response) {
                return $data;
            }
            $html = $this->renderer->render('aquaponie_data.twig', $data);
            $response->getBody()->write($html);

            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'CSRF') !== false) {
                $response->getBody()->write('Token CSRF invalide. Veuillez recharger la page et réessayer.');

                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('AquaponieController failure', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $response->getBody()->write('ERREUR AquaponieController: ' . $e->getMessage());

            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function buildPageContext(Request $request, Response $response, ?string $forcedPreset): array|Response
    {
        $catalog = $this->catalog;
        $environment = TableConfig::getEnvironment();
        $testSuffix = in_array($environment, ['test', 'test3', 's3test'], true) ? ' (TEST)' : '';

        $lastDate = $this->sensorReadRepo->getLastReadingDate();
        $defaultEndDate = $lastDate ?: date('Y-m-d H:i:s');
        $defaultStartDate = date('Y-m-d H:i:s', strtotime($defaultEndDate . ' -6 hours'));

        [$startDate, $endDate] = $this->dateRangeExtractor->extract($request, $defaultStartDate, $defaultEndDate);
        $periodNav = $this->dateRangeExtractor->resolveDataWindow($request, $startDate, $endDate);
        $startDate = $periodNav['start'];
        $endDate = $periodNav['end'];

        $body = $request->getParsedBody() ?? [];
        if (isset($body['export_csv'])) {
            return $this->csvExportService->export(
                $this->sensorReadRepo,
                $startDate,
                $endDate,
                $response,
                'sensor_data'
            );
        }

        $queryParams = $request->getQueryParams();
        if (isset($queryParams['legacy'])) {
            return $response->withStatus(302)->withHeader('Location', '/aquaponie');
        }

        $rowsAsc = Ffp3WaterLevelUnit::scaleSensorRowsFromMmToCm(
            $this->sensorReadRepo->fetchBetween($startDate, $endDate, 'ASC')
        );
        $measureCount = count($rowsAsc);

        $requestedPreset = $forcedPreset ?? AbstractDataController::requestedPreset($request);
        $display = $this->displayPresets->pageContext(
            $catalog,
            $requestedPreset,
            $this->authService->getCurrentRole()
        );

        $chartColumns = AbstractDataController::columnsForLayout($catalog, $display['layout']);
        $series = $this->chartDataService->prepareGenericSeries($rowsAsc, $chartColumns);

        $latestRow = [];
        if ($rowsAsc !== [] && $lastDate !== null && $endDate === $lastDate) {
            $latestRow = $rowsAsc[count($rowsAsc) - 1];
        } else {
            $latestRow = Ffp3WaterLevelUnit::scaleSensorRowFromMmToCm(
                $this->sensorReadRepo->getLastReadings()
            ) ?? [];
        }
        $latest = $latestRow !== [] ? $latestRow : null;

        $allStats = $this->statsAggregator->aggregateAllStats($startDate, $endDate);
        $statsFlattened = Ffp3WaterLevelUnit::scaleAquaponieFlattenedStatsFromMmToCm(
            $this->statsAggregator->flattenForLegacy($allStats)
        );
        $catalogStats = $this->statsFromFlattened($statsFlattened, AbstractDataController::statsColumnsForLayout($catalog, $display['layout']));

        $waterBalance = $this->waterBalanceService->computeBalance($startDate, $endDate, $rowsAsc);

        $extrema = $this->tideCycleDetector->detectExtremaSeries(
            array_column($rowsAsc, 'EauAquarium'),
            array_column($rowsAsc, 'reading_time')
        );

        $firstReadingDate = $this->sensorReadRepo->getFirstReadingDate();
        $tempsTotalModule = $firstReadingDate !== null
            ? DurationFormatter::short($firstReadingDate, date('Y-m-d H:i:s'))
            : null;

        $chartPayload = AbstractDataController::chartPayload($display['layout']['charts'], $series);
        $chartPayload['tidePeaks'] = $extrema['peaks'];
        $chartPayload['tideTroughs'] = $extrema['troughs'];

        $context = [
            'page_title' => 'Aquaponie FFP3 — Données en direct | n³ IoT' . $testSuffix,
            'nav_active' => 'aquaponie',
            'environment' => $environment,
            'data_config' => AbstractDataController::withPresetInFormAction(
                $catalog->pageConfig($environment),
                $requestedPreset,
                $display
            ),
            'realtime_api_base' => RealtimeUrlHelper::getRealtimeApiBase($environment),
            'latest' => $latest,
            'start_date' => \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDate),
            'end_date' => \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endDate),
            'measure_count' => $measureCount,
            'duration_str' => DurationFormatter::short($startDate, $endDate),
            'temps_total_module' => $tempsTotalModule,
            'firmware_version' => $this->sensorReadRepo->getFirmwareVersion(),
            'version' => Version::getWithPrefix(),
            'period_nav' => $periodNav,
            'page_key' => $catalog->pageKey(),
            'request_method' => strtoupper($request->getMethod()),
            'requested_preset' => $requestedPreset,
            'chart_payload' => $chartPayload,
            'data_table' => TableConfig::getDataTable(),
        ];

        return array_merge($context, $display, $catalogStats, $waterBalance);
    }

    /**
     * Mappe les stats aplaties legacy (avg_eauaqua…) vers avg_EauAquarium (lcfirst colonne).
     *
     * @param array<string, mixed> $flattened
     * @param list<string> $columns
     * @return array<string, float|null>
     */
    private function statsFromFlattened(array $flattened, array $columns): array
    {
        $legacyMap = [
            'EauAquarium' => 'eauaqua',
            'EauReserve' => 'eaureserve',
            'EauPotager' => 'eaupota',
            'TempEau' => 'tempeau',
            'TempAir' => 'tempair',
            'Humidite' => 'humi',
            'Luminosite' => 'lumi',
        ];

        $stats = [];
        foreach ($columns as $col) {
            $lc = lcfirst($col);
            $legacy = $legacyMap[$col] ?? $lc;
            $stats["avg_{$lc}"] = isset($flattened["avg_{$legacy}"]) ? (float) $flattened["avg_{$legacy}"] : null;
            $stats["min_{$lc}"] = isset($flattened["min_{$legacy}"]) ? (float) $flattened["min_{$legacy}"] : null;
            $stats["max_{$lc}"] = isset($flattened["max_{$legacy}"]) ? (float) $flattened["max_{$legacy}"] : null;
        }

        return $stats;
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
