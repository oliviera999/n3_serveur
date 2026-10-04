<?php

declare(strict_types=1);

namespace App\Controller\Energie;

use App\Controller\AbstractDataController;
use App\Repository\EnergieSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\TemplateRenderer;
use App\Util\RealtimeUrlHelper;

/**
 * Page publique du banc énergie (`/energie` en prod, `/energie-test` en energie_test) :
 * cartes live, polling temps réel et graphiques Highstock, sur le même schéma que MSP1.
 *
 * Les clés `section` de getSensorsConfig() rattachent chaque carte à un graphique
 * (id de getChartsConfig()) ou à la section « Système » (`systeme`).
 */
class EnergieDataController extends AbstractDataController
{
    public function __construct(
        TemplateRenderer $renderer,
        EnergieSensorRepository $sensorRepo,
        CsrfService $csrfService,
        DateRangeExtractor $dateRangeExtractor,
        CsvExportService $csvExportService,
        ChartDataService $chartDataService,
    ) {
        parent::__construct($renderer, $sensorRepo, $csrfService, $dateRangeExtractor, $csvExportService, $chartDataService);
    }

    /**
     * Identifiant nominal exigé par AbstractDataController : le banc énergie n'a AUCUNE
     * sortie (pas de table outputs, pas de ligne `Boards`). 8 n'est utilisé par aucune
     * autre famille (Boards 1–7 : FFP3, MSP1, N3PP, galeries).
     */
    protected function getBoard(): int
    {
        return 8;
    }

    /**
     * Ordre = ordre des séries des graphiques (cf. getSensorMapJson() et energie_data.twig).
     * PanneauP en tête : colonne de référence du sous-échantillonnage (la plus variable).
     */
    protected function getChartColumns(): array
    {
        return [
            // chart-puissances
            'PanneauP', 'BatterieP', 'ConsoP',
            // chart-tensions
            'PanneauV', 'BatterieV', 'BatterieVmin', 'ConsoV', 'BatterieVadc',
            // chart-courants
            'PanneauI', 'PanneauImax', 'BatterieI', 'BatterieImin', 'BatterieImax', 'ConsoI', 'ConsoImax',
            // chart-batterie
            'BatterieSoc', 'BatterieAh', 'EnergiePanneauWh', 'EnergieConsoWh',
        ];
    }

    protected function getStatsColumns(): array
    {
        return [
            'PanneauV', 'PanneauI', 'PanneauP',
            'BatterieV', 'BatterieI', 'BatterieP', 'BatterieSoc', 'BatterieAh',
            'ConsoV', 'ConsoI', 'ConsoP',
            'EnergiePanneauWh', 'EnergieConsoWh',
            'Rssi', 'I2cErreurs',
        ];
    }

    protected function getTemplateName(): string
    {
        return 'energie_data.twig';
    }

    protected function getPageTitle(string $testSuffix): string
    {
        return 'Banc énergie — INA226' . $testSuffix;
    }

    protected function getNavActive(): string
    {
        return 'energie';
    }

    protected function getCsvPrefix(): string
    {
        return 'energie_data';
    }

    protected function getRealtimeApiBase(string $environment): string
    {
        return RealtimeUrlHelper::getEnergieRealtimeApiBase($environment);
    }

    protected function getTestEnvironmentName(): string
    {
        return 'energie_test';
    }

    protected function getDataConfig(string $environment): array
    {
        $isTest = $environment === 'energie_test';

        return [
            'hero_title' => 'Banc énergie — INA226',
            'hero_icon' => 'fa-solar-panel',
            'hero_subtitle' => 'Production solaire, charge et décharge de la batterie 12 V, consommation des périphériques : '
                . 'trois capteurs INA226 mesurés chaque seconde, agrégés toutes les 10 s.',
            'form_action' => $isTest ? '/energie-test' : '/energie',
            'test_env' => 'energie_test',
            'table_label' => $isTest ? 'energieDataTest' : 'energieData',
            'footer_text' => 'Banc énergie (INA226)',
            'uptime_label' => 'Uptime (24 h)',
        ];
    }

    protected function getSensorsConfig(): array
    {
        return [
            // Puissances
            ['key' => 'PanneauP', 'label' => 'Puissance panneau', 'icon' => 'fa-solar-panel', 'color' => '#f39c12', 'unit' => 'W', 'decimals' => 2, 'section' => 'chart-puissances'],
            ['key' => 'BatterieP', 'label' => 'Puissance batterie', 'icon' => 'fa-car-battery', 'color' => '#27ae60', 'unit' => 'W', 'decimals' => 2, 'section' => 'chart-puissances', 'hint' => '> 0 charge · < 0 décharge'],
            ['key' => 'ConsoP', 'label' => 'Puissance conso', 'icon' => 'fa-plug', 'color' => '#e74c3c', 'unit' => 'W', 'decimals' => 2, 'section' => 'chart-puissances'],
            // Tensions
            ['key' => 'PanneauV', 'label' => 'Tension panneau', 'icon' => 'fa-solar-panel', 'color' => '#f39c12', 'unit' => 'V', 'decimals' => 2, 'section' => 'chart-tensions'],
            ['key' => 'BatterieV', 'label' => 'Tension batterie', 'icon' => 'fa-car-battery', 'color' => '#27ae60', 'unit' => 'V', 'decimals' => 2, 'section' => 'chart-tensions'],
            ['key' => 'ConsoV', 'label' => 'Tension conso', 'icon' => 'fa-plug', 'color' => '#e74c3c', 'unit' => 'V', 'decimals' => 2, 'section' => 'chart-tensions'],
            // Courants
            ['key' => 'PanneauI', 'label' => 'Courant panneau', 'icon' => 'fa-solar-panel', 'color' => '#f39c12', 'unit' => 'A', 'decimals' => 3, 'section' => 'chart-courants'],
            ['key' => 'BatterieI', 'label' => 'Courant batterie', 'icon' => 'fa-car-battery', 'color' => '#27ae60', 'unit' => 'A', 'decimals' => 3, 'section' => 'chart-courants', 'hint' => '> 0 charge · < 0 décharge'],
            ['key' => 'ConsoI', 'label' => 'Courant conso', 'icon' => 'fa-plug', 'color' => '#e74c3c', 'unit' => 'A', 'decimals' => 3, 'section' => 'chart-courants'],
            // Batterie & énergie
            ['key' => 'BatterieSoc', 'label' => 'État de charge', 'icon' => 'fa-battery-half', 'color' => '#16a085', 'unit' => '%', 'decimals' => 0, 'section' => 'chart-batterie'],
            ['key' => 'BatterieAh', 'label' => 'Compteur batterie (net)', 'icon' => 'fa-charging-station', 'color' => '#8e44ad', 'unit' => 'Ah', 'decimals' => 3, 'section' => 'chart-batterie'],
            ['key' => 'EnergiePanneauWh', 'label' => 'Énergie panneau (10 s)', 'icon' => 'fa-bolt', 'color' => '#f1c40f', 'unit' => 'Wh', 'decimals' => 3, 'section' => 'chart-batterie'],
            ['key' => 'EnergieConsoWh', 'label' => 'Énergie conso (10 s)', 'icon' => 'fa-bolt', 'color' => '#c0392b', 'unit' => 'Wh', 'decimals' => 3, 'section' => 'chart-batterie'],
            // Système
            ['key' => 'Rssi', 'label' => 'Signal WiFi', 'icon' => 'fa-wifi', 'color' => '#2c3e50', 'unit' => 'dBm', 'decimals' => 0, 'section' => 'systeme'],
            ['key' => 'I2cErreurs', 'label' => 'Erreurs I²C', 'icon' => 'fa-microchip', 'color' => '#7f8c8d', 'unit' => '', 'decimals' => 0, 'section' => 'systeme'],
        ];
    }

    protected function getChartsConfig(): array
    {
        return [
            [
                'id' => 'chart-puissances',
                'title' => 'Puissances',
                'icon' => 'fa-bolt',
                'legend_items' => [
                    ['name' => 'Panneau', 'color' => '#f39c12'],
                    ['name' => 'Batterie', 'color' => '#27ae60'],
                    ['name' => 'Conso', 'color' => '#e74c3c'],
                ],
            ],
            [
                'id' => 'chart-tensions',
                'title' => 'Tensions',
                'icon' => 'fa-wave-square',
                'legend_items' => [
                    ['name' => 'Panneau', 'color' => '#f39c12'],
                    ['name' => 'Batterie', 'color' => '#27ae60'],
                    ['name' => 'Batterie min.', 'color' => '#82e0aa'],
                    ['name' => 'Conso', 'color' => '#e74c3c'],
                    ['name' => 'Batterie (ADC)', 'color' => '#16a085'],
                ],
            ],
            [
                'id' => 'chart-courants',
                'title' => 'Courants',
                'icon' => 'fa-gauge-high',
                'legend_items' => [
                    ['name' => 'Panneau', 'color' => '#f39c12'],
                    ['name' => 'Panneau max.', 'color' => '#f8c471'],
                    ['name' => 'Batterie', 'color' => '#27ae60'],
                    ['name' => 'Batterie min.', 'color' => '#82e0aa'],
                    ['name' => 'Batterie max.', 'color' => '#1e8449'],
                    ['name' => 'Conso', 'color' => '#e74c3c'],
                    ['name' => 'Conso max.', 'color' => '#f1948a'],
                ],
            ],
            [
                'id' => 'chart-batterie',
                'title' => 'Batterie & énergie',
                'icon' => 'fa-battery-half',
                'legend_items' => [
                    ['name' => 'État de charge', 'color' => '#16a085'],
                    ['name' => 'Compteur Ah', 'color' => '#8e44ad'],
                    ['name' => 'Énergie panneau', 'color' => '#f1c40f'],
                    ['name' => 'Énergie conso', 'color' => '#c0392b'],
                ],
            ],
        ];
    }

    /**
     * Capteur → graphique + index de série, dans l'ordre exact où energie_data.twig
     * crée les séries (le ChartUpdaterGeneric y ajoute les points live).
     */
    protected function getSensorMapJson(): string
    {
        return '{
            "PanneauP":{"chartId":"chart-puissances","seriesIndex":0},
            "BatterieP":{"chartId":"chart-puissances","seriesIndex":1},
            "ConsoP":{"chartId":"chart-puissances","seriesIndex":2},
            "PanneauV":{"chartId":"chart-tensions","seriesIndex":0},
            "BatterieV":{"chartId":"chart-tensions","seriesIndex":1},
            "BatterieVmin":{"chartId":"chart-tensions","seriesIndex":2},
            "ConsoV":{"chartId":"chart-tensions","seriesIndex":3},
            "BatterieVadc":{"chartId":"chart-tensions","seriesIndex":4},
            "PanneauI":{"chartId":"chart-courants","seriesIndex":0},
            "PanneauImax":{"chartId":"chart-courants","seriesIndex":1},
            "BatterieI":{"chartId":"chart-courants","seriesIndex":2},
            "BatterieImin":{"chartId":"chart-courants","seriesIndex":3},
            "BatterieImax":{"chartId":"chart-courants","seriesIndex":4},
            "ConsoI":{"chartId":"chart-courants","seriesIndex":5},
            "ConsoImax":{"chartId":"chart-courants","seriesIndex":6},
            "BatterieSoc":{"chartId":"chart-batterie","seriesIndex":0},
            "BatterieAh":{"chartId":"chart-batterie","seriesIndex":1},
            "EnergiePanneauWh":{"chartId":"chart-batterie","seriesIndex":2},
            "EnergieConsoWh":{"chartId":"chart-batterie","seriesIndex":3}
        }';
    }
}
