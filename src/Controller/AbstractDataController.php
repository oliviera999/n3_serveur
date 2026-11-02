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
use App\Util\ReadingTimeParser;
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

        $requestedPreset = self::requestedPreset($request);
        // Sans ?preset= explicite : dernier choix du visiteur (cookie posé par preset-selector.js).
        $display = $this->displayPresets->pageContext(
            $catalog,
            $requestedPreset ?? self::rememberedPreset($request, $catalog->pageKey())
        );

        // fetchBetween() renvoie déjà les lectures en ordre chronologique (ASC) et
        // prepareGenericSeries() conserve cet ordre. Highstock exige un axe X
        // croissant : inverser ici produirait des timestamps décroissants et
        // déclencherait l'avertissement Highcharts #15 (« data not sorted in
        // ascending X order »).
        // Colonnes limitées au preset actif (séries non hidden) : allège le JSON envoyé
        // (la décimation LTTB, elle, ne dépend que de la 1re colonne).
        $series = $this->chartDataService->prepareGenericSeries(
            $readings,
            self::columnsForLayout($catalog, $display['layout'])
        );

        $stats = $this->computeStats(
            $readings,
            self::statsColumnsForLayout($catalog, $display['layout'])
        );

        $latest = $this->sensorRepo->getLatest();
        $firstReadingDate = $this->sensorRepo->getFirstReadingDate();
        $now = date('Y-m-d H:i:s');
        $tempsTotalModule = $firstReadingDate !== null
            ? DurationFormatter::short($firstReadingDate, $now)
            : null;

        $context = [
            'page_title' => $this->getPageTitle($testSuffix),
            'nav_active' => $this->getNavActive(),
            'environment' => $environment,
            'data_config' => self::withPresetInFormAction($catalog->pageConfig($environment), $requestedPreset, $display),
            'realtime_api_base' => $this->getRealtimeApiBase($environment),
            'latest' => $latest,
            'last_valid' => self::lastValidValues(
                $display['layout'],
                $latest,
                fn (string $column): ?array => $this->sensorRepo->getLastNonNull($column, self::lastValidSince($lastDate))
            ),
            'stuck' => self::stuckSensors($display['layout'], $readings),
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

    /** Profondeur de recherche de la dernière valeur valide d'une carte vide (jours). */
    public const LAST_VALID_LOOKBACK_DAYS = 30;

    /** Seuils « capteur figé » : mesures non nulles et durée minimales sur la période. */
    public const STUCK_MIN_READINGS = 20;
    public const STUCK_MIN_SECONDS = 7200;

    /** Borne basse (SQL) de la recherche de dernière valeur valide. */
    public static function lastValidSince(?string $lastReadingDate): string
    {
        try {
            $ref = new \DateTimeImmutable($lastReadingDate ?? 'now');
        } catch (\Exception) {
            $ref = new \DateTimeImmutable();
        }

        // modify() (et non -N×86400 s) : borne en heure murale, indépendante du changement d'heure.
        return $ref->modify('-' . self::LAST_VALID_LOOKBACK_DAYS . ' days')->format('Y-m-d H:i:s');
    }

    /**
     * Cartes visibles dont la dernière mesure est vide : dernière valeur valide connue
     * et sa date, affichées sous « — » (ex. capteur sans mesure depuis…).
     *
     * @param array<string, mixed> $layout sortie de LayoutResolver::resolve()
     * @param array<string, mixed>|null $latest
     * @param callable(string): (array{value: float, reading_time: string}|null) $lookup
     * @return array<string, array{value: float, reading_time: string}>
     */
    public static function lastValidValues(array $layout, ?array $latest, callable $lookup): array
    {
        if ($latest === null) {
            return [];
        }
        $out = [];
        foreach ($layout['blocks'] ?? [] as $block) {
            foreach ($block['cards'] ?? [] as $card) {
                $key = (string) $card['key'];
                if (!empty($card['if_present']) || isset($out[$key])) {
                    continue;
                }
                $value = $latest[$key] ?? null;
                if ($value !== null && $value !== '') {
                    continue;
                }
                $found = $lookup($key);
                if ($found !== null) {
                    $out[$key] = $found;
                }
            }
        }

        return $out;
    }

    /**
     * Cartes surveillées (option catalogue `stuck_check`) dont toutes les mesures de la
     * période sont identiques : capteur probablement figé (ex. niveau aquarium bloqué à
     * la même valeur pendant des heures alors que les marées le font varier).
     *
     * @param array<string, mixed> $layout sortie de LayoutResolver::resolve()
     * @param array<int, array<string, mixed>> $readings lectures de la période
     * @return array<string, float> clé de carte => valeur répétée
     */
    public static function stuckSensors(array $layout, array $readings): array
    {
        $out = [];
        foreach ($layout['blocks'] ?? [] as $block) {
            foreach ($block['cards'] ?? [] as $card) {
                if (empty($card['stuck_check'])) {
                    continue;
                }
                $key = (string) $card['key'];
                $count = 0;
                $first = null;
                $same = true;
                $tMin = null;
                $tMax = null;
                foreach ($readings as $row) {
                    $value = $row[$key] ?? null;
                    if ($value === null || $value === '' || !is_numeric($value)) {
                        continue;
                    }
                    $count++;
                    $first ??= (float) $value;
                    if ((float) $value !== $first) {
                        $same = false;
                        break;
                    }
                    $t = ReadingTimeParser::toUnixSeconds((string) ($row['reading_time'] ?? ''));
                    if ($t !== null) {
                        $tMin = $tMin === null ? $t : min($tMin, $t);
                        $tMax = $tMax === null ? $t : max($tMax, $t);
                    }
                }
                if (
                    $same && $first !== null && $count >= self::STUCK_MIN_READINGS
                    && $tMin !== null && $tMax !== null && ($tMax - $tMin) >= self::STUCK_MIN_SECONDS
                ) {
                    $out[$key] = $first;
                }
            }
        }

        return $out;
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

    /** Préfixe du cookie mémorisant le dernier preset choisi sur une page (`n3_preset_<page>`). */
    public const PRESET_COOKIE_PREFIX = 'n3_preset_';

    /**
     * Dernier preset choisi par le visiteur sur cette page (cookie `n3_preset_<page>`
     * posé par preset-selector.js), ou null. Un slug inconnu retombe sur le défaut
     * dans DisplayPresetService::select().
     */
    public static function rememberedPreset(Request $request, string $pageKey): ?string
    {
        $raw = $request->getCookieParams()[self::PRESET_COOKIE_PREFIX . $pageKey] ?? null;
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
     * Colonnes graphiques du catalogue réellement utilisées par le layout résolu
     * (ordre catalogue conservé pour la colonne de référence LTTB).
     *
     * @param array<string, mixed> $layout sortie de LayoutResolver::resolve()
     * @return list<string>
     */
    public static function columnsForLayout(PageCatalog $catalog, array $layout): array
    {
        $needed = [];
        foreach ($layout['charts'] ?? [] as $chart) {
            foreach ($chart['series'] ?? [] as $series) {
                if (!empty($series['column'])) {
                    $needed[(string) $series['column']] = true;
                }
                if (!empty($series['trend_of'])) {
                    $needed[(string) $series['trend_of']] = true;
                }
            }
        }
        if ($needed === []) {
            return $catalog->chartColumns();
        }

        $ordered = [];
        foreach ($catalog->chartColumns() as $column) {
            if (isset($needed[$column])) {
                $ordered[] = $column;
            }
        }

        return $ordered !== [] ? $ordered : array_keys($needed);
    }

    /**
     * Colonnes de stats limitées aux cartes visibles du layout (sinon catalogue).
     *
     * @param array<string, mixed> $layout
     * @return list<string>
     */
    public static function statsColumnsForLayout(PageCatalog $catalog, array $layout): array
    {
        $keys = [];
        foreach ($layout['blocks'] ?? [] as $block) {
            foreach ($block['cards'] ?? [] as $card) {
                if (!empty($card['stats']) && !empty($card['key'])) {
                    $keys[(string) $card['key']] = true;
                }
            }
        }
        if ($keys === []) {
            return $catalog->statsColumns();
        }

        $ordered = [];
        foreach ($catalog->statsColumns() as $column) {
            if (isset($keys[$column])) {
                $ordered[] = $column;
            }
        }

        return $ordered !== [] ? $ordered : array_keys($keys);
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
                    'id' => $s['id'],
                    'name' => $s['name'],
                    'column' => $s['column'],
                    'trendOf' => $s['trend_of'],
                    'trendRound' => $s['trend_round'],
                    'trendKind' => $s['trend_kind'] ?? 'linear',
                    'trendWindow' => $s['trend_window'] ?? 30,
                    'type' => $s['type'],
                    'color' => $s['color'],
                    'fill' => $s['fill'],
                    'lineWidth' => $s['lineWidth'],
                    'dashStyle' => $s['dashStyle'],
                    'yAxis' => $s['yAxis'],
                    'tooltip' => $s['tooltip'],
                    'opacity' => $s['opacity'],
                    'step' => $s['step'] ?? null,
                    'onMarkers' => $s['on_markers'] ?? null,
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
            // Points « réchauffés » au réveil, omis par colonne côté graphique (escaliers).
            'stale' => (object) ChartDataService::staleWakeIndexes($series, array_keys($columns)),
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
