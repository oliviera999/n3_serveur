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
 * Contrôleur abstrait pour les pages de données MSP1 et N3PP.
 * Factorise le flux commun : extraction dates, fetch, stats, export CSV, rendu.
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
    ) {
    }

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

    /** @return array<string, mixed> */
    abstract protected function getDataConfig(string $environment): array;

    /** @return array<int, array<string, mixed>> */
    abstract protected function getSensorsConfig(): array;

    /** @return array<int, array<string, mixed>> */
    abstract protected function getChartsConfig(): array;

    abstract protected function getSensorMapJson(): string;

    public function show(Request $request, Response $response): Response
    {
        $environment = TableConfig::getEnvironment();
        $testSuffix = $environment === $this->getTestEnvironmentName() ? ' (TEST)' : '';

        $defaultEnd = date('Y-m-d H:i:s');
        $defaultStart = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $lastDate = $this->sensorRepo->getLastReadingDate();
        if ($lastDate !== null) {
            $defaultEnd = $lastDate;
            $defaultStart = date('Y-m-d H:i:s', strtotime($lastDate . ' -24 hours'));
        }

        try {
            [$startDate, $endDate] = $this->dateRangeExtractor->extract($request, $defaultStart, $defaultEnd);
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'CSRF') !== false) {
                $response->getBody()->write('Token CSRF invalide. Veuillez recharger la page.');
                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
            throw $e;
        }

        $readings = $this->sensorRepo->fetchBetween($startDate, $endDate);
        $measureCount = count($readings);

        $body = $request->getParsedBody() ?? [];
        if (isset($body['export_csv']) && $measureCount > 0) {
            return $this->csvExportService->export(
                $this->sensorRepo,
                $startDate,
                $endDate,
                $response,
                $this->getCsvPrefix()
            );
        }

        $chartColumns = $this->getChartColumns();
        $series = $this->chartDataService->prepareGenericSeries(
            array_reverse($readings),
            $chartColumns
        );

        $stats = $this->computeStats($readings);

        $latest = $this->sensorRepo->getLatest();

        $chartsConfig = $this->getChartsConfig();
        $chartIds = array_column($chartsConfig, 'id');

        $context = [
            'page_title' => $this->getPageTitle($testSuffix),
            'nav_active' => $this->getNavActive(),
            'environment' => $environment,
            'data_config' => $this->getDataConfig($environment),
            'sensors_config' => $this->getSensorsConfig(),
            'charts_config' => $chartsConfig,
            'chart_columns' => $chartColumns,
            'chart_ids_json' => json_encode($chartIds),
            'sensor_map_json' => $this->getSensorMapJson(),
            'realtime_api_base' => $this->getRealtimeApiBase($environment),
            'latest' => $latest,
            'start_date' => \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDate),
            'end_date' => \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endDate),
            'measure_count' => $measureCount,
            'duration_str' => DurationFormatter::short($startDate, $endDate),
            'firmware_version' => $this->sensorRepo->getFirmwareVersion(),
            'version' => Version::getWithPrefix(),
        ];

        $context = array_merge($context, $series, $stats);

        $html = $this->renderer->render($this->getTemplateName(), $context);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param array<int, array<string, mixed>> $readings
     * @return array<string, float|null>
     */
    private function computeStats(array $readings): array
    {
        $stats = [];
        foreach ($this->getStatsColumns() as $col) {
            $lc = lcfirst($col);
            $values = array_filter(
                array_column($readings, $col),
                fn($v) => $v !== null && $v !== '' && is_numeric($v)
            );
            $stats["avg_{$lc}"] = $values !== [] ? array_sum($values) / count($values) : null;
            $stats["min_{$lc}"] = $values !== [] ? min($values) : null;
            $stats["max_{$lc}"] = $values !== [] ? max($values) : null;
        }
        return $stats;
    }
}
