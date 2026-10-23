<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Config\Version;
use App\Display\PageCatalog;
use App\Repository\AbstractSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\DisplayPresetService;
use App\Service\TemplateRenderer;
use App\Util\DurationFormatter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Contrôleur abstrait des pages de suivi pilotées par catalogue (MSP1, N3PP, ENERGIE).
 * Factorise le flux commun : extraction dates, fetch, stats, export CSV, preset
 * d'affichage (?preset=) et rendu générique (monitoring_page.twig).
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
        protected DisplayPresetService $displayPresets,
    ) {
    }

    abstract protected function catalog(): PageCatalog;

    abstract protected function getTemplateName(): string;

    abstract protected function getPageTitle(string $testSuffix): string;

    abstract protected function getNavActive(): string;

    abstract protected function getCsvPrefix(): string;

    abstract protected function getRealtimeApiBase(string $environment): string;

    abstract protected function getTestEnvironmentName(): string;

    public function show(Request $request, Response $response): Response
    {
        $environment = TableConfig::getEnvironment();
        $testSuffix = $environment === $this->getTestEnvironmentName() ? ' (TEST)' : '';
        $catalog = $this->catalog();

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

        // Navigation par fenêtre temporelle (GET ?from/to ou ?window=&offset=).
        // N'intervient que si le POST n'a pas déjà fixé une plage personnalisée :
        // sans param GET reconnu, la fenêtre reste strictement celle ci-dessus.
        $periodNav = $this->dateRangeExtractor->resolveDataWindow($request, $startDate, $endDate);
        $startDate = $periodNav['start'];
        $endDate = $periodNav['end'];

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

        // fetchBetween() renvoie déjà les lectures en ordre chronologique (ASC) et
        // prepareGenericSeries() conserve cet ordre. Highstock exige un axe X
        // croissant : inverser ici produirait des timestamps décroissants et
        // déclencherait l'avertissement Highcharts #15 (« data not sorted in
        // ascending X order »).
        $series = $this->chartDataService->prepareGenericSeries(
            $readings,
            $catalog->chartColumns()
        );

        $stats = $this->computeStats($readings, $catalog->statsColumns());

        $firstReadingDate = $this->sensorRepo->getFirstReadingDate();
        $now = date('Y-m-d H:i:s');
        $tempsTotalModule = $firstReadingDate !== null
            ? DurationFormatter::short($firstReadingDate, $now)
            : null;

        $requestedPreset = self::requestedPreset($request);
        $display = $this->displayPresets->pageContext($catalog, $requestedPreset);

        $context = [
            'page_title' => $this->getPageTitle($testSuffix),
            'nav_active' => $this->getNavActive(),
            'environment' => $environment,
            'data_config' => self::withPresetInFormAction($catalog->pageConfig($environment), $requestedPreset, $display),
            'realtime_api_base' => $this->getRealtimeApiBase($environment),
            'latest' => $this->sensorRepo->getLatest(),
            'start_date' => \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startDate),
            'end_date' => \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endDate),
            'measure_count' => $measureCount,
            'duration_str' => DurationFormatter::short($startDate, $endDate),
            'temps_total_module' => $tempsTotalModule,
            'firmware_version' => $this->sensorRepo->getFirmwareVersion(),
            'version' => Version::getWithPrefix(),
            'period_nav' => $periodNav,
            'page_key' => $catalog->pageKey(),
            'request_method' => strtoupper($request->getMethod()),
            'requested_preset' => $requestedPreset,
            'chart_payload' => self::chartPayload($display['layout']['charts'], $series),
        ];

        $context = array_merge($context, $display, $stats);

        $html = $this->renderer->render($this->getTemplateName(), $context);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Slug de preset demandé (?preset= en GET, champ `preset` d'un POST), ou null.
     */
    public static function requestedPreset(Request $request): ?string
    {
        $body = $request->getParsedBody();
        $raw = $request->getQueryParams()['preset'] ?? (is_array($body) ? ($body['preset'] ?? null) : null);
        if (!is_string($raw) || !preg_match(DisplayPresetService::SLUG_PATTERN, $raw)) {
            return null;
        }

        return $raw;
    }

    /**
     * Conserve le preset choisi lors d'un POST du formulaire de période.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $display
     * @return array<string, mixed>
     */
    public static function withPresetInFormAction(array $config, ?string $requestedPreset, array $display): array
    {
        if ($requestedPreset !== null && $display['preset']['slug'] === $requestedPreset) {
            $config['form_action'] .= '?preset=' . rawurlencode($requestedPreset);
        }

        return $config;
    }

    /**
     * Données JSON des graphiques à créer : horodatages, colonnes utilisées par
     * les séries rendues (pas les autres) et définitions des graphiques.
     *
     * @param list<array<string, mixed>> $charts
     * @param array<string, mixed> $series sortie de ChartDataService::prepareGenericSeries()
     * @return array<string, mixed>
     */
    public static function chartPayload(array $charts, array $series): array
    {
        $columns = [];
        $jsCharts = [];
        foreach ($charts as $chart) {
            $jsSeries = [];
            foreach ($chart['series'] as $s) {
                $source = $s['column'] ?? $s['trend_of'];
                $columns[$source] = $series[$source] ?? [];
                $jsSeries[] = [
                    'name' => $s['name'],
                    'column' => $s['column'],
                    'trendOf' => $s['trend_of'],
                    'trendRound' => $s['trend_round'],
                    'type' => $s['type'],
                    'color' => $s['color'],
                    'fill' => $s['fill'],
                    'lineWidth' => $s['lineWidth'],
                    'dashStyle' => $s['dashStyle'],
                    'yAxis' => $s['yAxis'],
                    'tooltip' => $s['tooltip'],
                    'opacity' => $s['opacity'],
                    'visible' => $s['visible'],
                ];
            }
            $jsCharts[] = [
                'id' => $chart['id'],
                'kind' => $chart['kind'],
                'yAxis' => $chart['yAxis'],
                'tooltip' => $chart['tooltip'],
                'series' => $jsSeries,
            ];
        }

        return [
            'readingTime' => $series['reading_time'] ?? [],
            'columns' => (object) $columns,
            'charts' => $jsCharts,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $readings
     * @param list<string> $statsColumns
     * @return array<string, float|null>
     */
    private function computeStats(array $readings, array $statsColumns): array
    {
        $stats = [];
        foreach ($statsColumns as $col) {
            $lc = lcfirst($col);
            $values = array_filter(
                array_column($readings, $col),
                fn ($v) => $v !== null && $v !== '' && is_numeric($v)
            );
            $stats["avg_{$lc}"] = $values !== [] ? array_sum($values) / count($values) : null;
            $stats["min_{$lc}"] = $values !== [] ? min($values) : null;
            $stats["max_{$lc}"] = $values !== [] ? max($values) : null;
        }
        return $stats;
    }
}
