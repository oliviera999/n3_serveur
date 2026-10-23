<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Config\Version;
use App\Display\LayoutResolver;
use App\Display\PageCatalog;
use App\Display\PageCatalogRegistry;
use App\Service\TemplateRenderer;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Rendu local (php -S / cli-server) des pages de suivi sans MySQL.
 * MSP1 / N3PP : même contrat Twig que AbstractDataController, avec les presets
 * intégrés uniquement (pas de DisplayPresetRepository / PDO).
 * FFP3 : contexte historique dédié (aquaponie_*.twig encore hors catalogue).
 */
class LocalDataPagesController
{
    public function __construct(
        private TemplateRenderer $renderer,
        private PageCatalogRegistry $catalogRegistry,
        private LayoutResolver $layoutResolver,
    ) {
    }

    public function showAquaponie(Request $request, Response $response): Response
    {
        $context = $this->buildAquaponieContext('aquaponie_alt.twig');
        return $this->render($response, 'aquaponie_alt.twig', $context);
    }

    public function showAquaponieClassic(Request $request, Response $response): Response
    {
        $context = $this->buildAquaponieContext('aquaponie.twig');
        return $this->render($response, 'aquaponie.twig', $context);
    }

    public function showMsp1(Request $request, Response $response): Response
    {
        return $this->renderCatalogPage(
            $request,
            $response,
            $this->catalogRegistry->get('msp1'),
            'msp1_data.twig',
            'Données station météo - Le potager (local)',
            'potager',
            '/msp1/api/realtime'
        );
    }

    public function showN3pp(Request $request, Response $response): Response
    {
        return $this->renderCatalogPage(
            $request,
            $response,
            $this->catalogRegistry->get('n3pp'),
            'n3pp_data.twig',
            'Données serre / élevage - n3 iot (local)',
            'elevage',
            '/n3pp/api/realtime'
        );
    }

    private function renderCatalogPage(
        Request $request,
        Response $response,
        ?PageCatalog $catalog,
        string $template,
        string $pageTitle,
        string $navActive,
        string $realtimeApiBase
    ): Response {
        if ($catalog === null) {
            $response->getBody()->write('Catalogue d\'affichage introuvable.');
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $this->logLocalFallback($template);
        $environment = TableConfig::getEnvironment();
        $range = $this->getDefaultRange('-24 hours');
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $range['start']);
        $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $range['end']);

        $requestedPreset = AbstractDataController::requestedPreset($request);
        $display = $this->builtInPageContext($catalog, $requestedPreset);
        $dataConfig = AbstractDataController::withPresetInFormAction(
            $catalog->pageConfig($environment),
            $requestedPreset,
            $display
        );

        $stats = [];
        foreach ($catalog->statsColumns() as $col) {
            $lc = lcfirst($col);
            $stats["avg_{$lc}"] = null;
            $stats["min_{$lc}"] = null;
            $stats["max_{$lc}"] = null;
        }

        $context = array_merge([
            'page_title' => $pageTitle,
            'nav_active' => $navActive,
            'environment' => $environment,
            'data_config' => $dataConfig,
            'realtime_api_base' => $realtimeApiBase,
            'latest' => null,
            'start_date' => $start,
            'end_date' => $end,
            'measure_count' => 0,
            'duration_str' => '0 j, 0 h, 0 min',
            'temps_total_module' => null,
            'firmware_version' => '-',
            'version' => Version::getWithPrefix(),
            'period_nav' => [
                'start' => $range['start'],
                'end' => $range['end'],
                'mode' => 'default',
                'window' => null,
                'offset' => 0,
                'has_prev' => true,
                'has_next' => false,
                'prev_offset' => null,
                'next_offset' => null,
                'width_seconds' => 86400,
            ],
            'page_key' => $catalog->pageKey(),
            'request_method' => strtoupper($request->getMethod()),
            'requested_preset' => $requestedPreset,
            'chart_payload' => [
                'readingTime' => [],
                'columns' => (object) [],
                'charts' => [],
            ],
        ], $display, $stats);

        return $this->render($response, $template, $context);
    }

    /**
     * Contexte d'affichage à partir des presets intégrés seulement (sans BDD).
     *
     * @return array{layout: array<string, mixed>, preset: array<string, mixed>, default_preset_slug: string, presets_menu: list<array<string, mixed>>, chart_ids_json: string, sensor_map_json: string}
     */
    private function builtInPageContext(PageCatalog $catalog, ?string $requestedSlug): array
    {
        $presets = [];
        foreach ($catalog->builtInPresets() as $slug => $preset) {
            $presets[] = $preset + [
                'builtin' => true,
                'customized' => false,
                'updated_at' => null,
                'updated_by' => null,
            ];
        }
        usort($presets, static fn (array $a, array $b): int => [$a['sort_order'], $a['label']] <=> [$b['sort_order'], $b['label']]);

        $default = $presets[0];
        $selected = $default;
        foreach ($presets as $preset) {
            if ($requestedSlug !== null && $preset['slug'] === $requestedSlug) {
                $selected = $preset;
            }
            if ($preset['is_default']) {
                $default = $preset;
                if ($requestedSlug === null) {
                    $selected = $preset;
                }
            }
        }

        $layout = $this->layoutResolver->resolve($catalog, $selected['layout']);
        $menu = [];
        foreach ($presets as $p) {
            if ($p['show_on_page'] || $p['slug'] === $selected['slug']) {
                $menu[] = [
                    'slug' => $p['slug'],
                    'label' => $p['label'],
                    'description' => $p['description'],
                    'is_default' => $p['is_default'],
                    'active' => $p['slug'] === $selected['slug'],
                ];
            }
        }

        return [
            'layout' => $layout,
            'preset' => ['slug' => $selected['slug'], 'label' => $selected['label']],
            'default_preset_slug' => $default['slug'],
            'presets_menu' => $menu,
            'chart_ids_json' => (string) json_encode(array_column($layout['charts'], 'id')),
            'sensor_map_json' => (string) json_encode((object) $layout['sensor_map']),
        ];
    }

    private function buildAquaponieContext(string $template): array
    {
        $this->logLocalFallback($template);

        $range = $this->getDefaultRange('-6 hours');

        return [
            'page_title' => 'Aquaponie - mode local sans base',
            'start_date' => $range['start'],
            'end_date' => $range['end'],
            'reading_time' => '[]',
            'measure_count' => 0,
            'duration_str' => '0 jours, 0 heures, 0 minutes, 0 secondes',
            'version' => Version::getWithPrefix(),
            'firmware_version' => '-',
            'environment' => TableConfig::getEnvironment(),
            'data_table' => TableConfig::getDataTable(),
            'last_reading_tempair' => 0,
            'last_reading_tempeau' => 0,
            'last_reading_humi' => 0,
            'last_reading_lumi' => 0,
            'last_reading_eauaqua' => 0,
            'last_reading_eaureserve' => 0,
            'last_reading_eaupota' => 0,
            'min_tempair' => null,
            'max_tempair' => null,
            'avg_tempair' => null,
            'stddev_tempair' => null,
            'min_tempeau' => null,
            'max_tempeau' => null,
            'avg_tempeau' => null,
            'stddev_tempeau' => null,
            'min_humi' => null,
            'max_humi' => null,
            'avg_humi' => null,
            'stddev_humi' => null,
            'min_lumi' => null,
            'max_lumi' => null,
            'avg_lumi' => null,
            'stddev_lumi' => null,
            'min_eauaqua' => null,
            'max_eauaqua' => null,
            'avg_eauaqua' => null,
            'stddev_eauaqua' => null,
            'min_eaureserve' => null,
            'max_eaureserve' => null,
            'avg_eaureserve' => null,
            'stddev_eaureserve' => null,
            'min_eaupota' => null,
            'max_eaupota' => null,
            'avg_eaupota' => null,
            'stddev_eaupota' => null,
            'reserve_consumption' => null,
            'reserve_refill' => null,
            'reserve_balance' => null,
            'tide_frequency' => null,
            'tide_frequency_stddev' => null,
            'tide_marnage' => null,
            'tide_marnage_stddev' => null,
            'tide_cycles' => 0,
            'aquarium_consumption' => null,
            'aquarium_consumption_per_day' => null,
            'aquarium_trend_slope_per_day' => null,
            'EauAquarium' => '[]',
            'EauReserve' => '[]',
            'EauPotager' => '[]',
            'TempAir' => '[]',
            'TempEau' => '[]',
            'Humidite' => '[]',
            'Luminosite' => '[]',
            'etatPompeAqua' => '[]',
            'etatPompeTank' => '[]',
            'etatHeat' => '[]',
            'etatUV' => '[]',
            'bouffePetits' => '[]',
            'bouffeGros' => '[]',
        ];
    }

    private function render(Response $response, string $template, array $context): Response
    {
        $html = $this->renderer->render($template, $context);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @return array{start: string, end: string}
     */
    private function getDefaultRange(string $relativeStart): array
    {
        $end = new DateTimeImmutable();
        $start = $end->modify($relativeStart);

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];
    }

    private function logLocalFallback(string $template): void
    {
        unset($template);
    }
}
