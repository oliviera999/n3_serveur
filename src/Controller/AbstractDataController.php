<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\AbstractSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\TemplateRenderer;
use App\Util\DurationFormatter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur abstrait pour les pages de données (MSP1, N3PP).
 * Factorise le flux commun : extraction dates, fetch, stats, charts, export CSV, rendu.
 */
abstract class AbstractDataController
{
    public function __construct(
        protected TemplateRenderer $renderer,
        protected AbstractSensorRepository $sensorRepo,
        protected CsrfService $csrfService,
        protected DateRangeExtractor $dateRangeExtractor,
        protected CsvExportService $csvExportService,
        protected ChartDataService $chartDataService,
    ) {}

    abstract protected function getBoard(): int;
    /** @return string[] */
    abstract protected function getChartColumns(): array;
    /** @return string[] */
    abstract protected function getStatsColumns(): array;
    abstract protected function getTemplateName(): string;
    abstract protected function getPageTitle(string $testSuffix): string;
    abstract protected function getNavActive(): string;
    abstract protected function getCsvPrefix(): string;
    abstract protected function getRealtimeApiBase(string $environment): string;
    abstract protected function getTestEnvironmentName(): string;

    /**
     * Configuration de la page de données (hero, form, footer).
     * @return array{hero_title: string, hero_icon: string, hero_subtitle: string, form_action: string, test_env: string, table_label: string, footer_text: string}
     */
    abstract protected function getDataConfig(string $environment): array;

    /**
     * Configuration des capteurs pour les stat-cards.
     * @return array<int, array{key?: string, label?: string, icon: string, class?: string, unit?: string, decimals?: int, unit_suffix?: string, no_stats?: bool, stats_key?: string, loop?: array}>
     */
    abstract protected function getSensorsConfig(): array;

    /**
     * Configuration des graphiques (containers).
     * @return array<int, array{id: string, title: string, icon: string, height?: string}>
     */
    abstract protected function getChartsConfig(): array;

    /** JSON du mapping capteur -> graphique pour ChartUpdaterGeneric. */
    abstract protected function getSensorMapJson(): string;

    public function show(Request $request, Response $response): Response
    {
        $lastDate = $this->sensorRepo->getLastReadingDate();
        $defaultEnd = $lastDate ?: date('Y-m-d H:i:s');
        $defaultStart = date('Y-m-d H:i:s', strtotime($defaultEnd . ' -24 hours'));

        [$startDate, $endDate] = $this->dateRangeExtractor->extract($request, $defaultStart, $defaultEnd);

        $body = $request->getParsedBody() ?? [];
        if (isset($body['export_csv'])) {
            return $this->csvExportService->export(
                $this->sensorRepo, $startDate, $endDate, $response, $this->getCsvPrefix()
            );
        }

        $readings = $this->sensorRepo->fetchBetween($startDate, $endDate);
        $measureCount = count($readings);

        $chartData = $this->chartDataService->prepareGenericSeries($readings, $this->getChartColumns());
        $latest = $this->sensorRepo->getLatest();
        $firmwareVersion = $this->sensorRepo->getFirmwareVersion();

        $stats = [];
        foreach ($this->getStatsColumns() as $col) {
            $s = $this->sensorRepo->getColumnStats($col, $startDate, $endDate);
            $lc = lcfirst($col);
            $stats["avg_$lc"] = $s['avg'];
            $stats["min_$lc"] = $s['min'];
            $stats["max_$lc"] = $s['max'];
            $stats["stddev_$lc"] = $s['stddev'];
        }

        $env = TableConfig::getEnvironment();
        $testSuffix = $env === $this->getTestEnvironmentName() ? ' (TEST)' : '';

        $chartIds = array_map(fn($c) => $c['id'], $this->getChartsConfig());

        $html = $this->renderer->render($this->getTemplateName(), array_merge([
            'page_title' => $this->getPageTitle($testSuffix),
            'nav_active' => $this->getNavActive(),
            'latest' => $latest,
            'board' => $this->getBoard(),
            'version' => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment' => $env,
            'realtime_api_base' => $this->getRealtimeApiBase($env),
            'csrf_field' => $this->csrfService->getHiddenField(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'measure_count' => $measureCount,
            'duration_str' => DurationFormatter::short($startDate, $endDate),
            'data_config' => $this->getDataConfig($env),
            'sensors_config' => $this->getSensorsConfig(),
            'charts_config' => $this->getChartsConfig(),
            'chart_columns' => $this->getChartColumns(),
            'chart_ids_json' => json_encode($chartIds),
            'sensor_map_json' => $this->getSensorMapJson(),
        ], $chartData, $stats));

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
