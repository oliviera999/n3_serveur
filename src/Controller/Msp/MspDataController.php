<?php

declare(strict_types=1);

namespace App\Controller\Msp;

use App\Controller\AbstractDataController;
use App\Repository\MspSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\TemplateRenderer;
use App\Util\RealtimeUrlHelper;

/**
 * Page de données de la station météo (MSP1).
 * Hérite du flux commun AbstractDataController.
 */
class MspDataController extends AbstractDataController
{
    public function __construct(
        TemplateRenderer $renderer,
        MspSensorRepository $sensorRepo,
        CsrfService $csrfService,
        DateRangeExtractor $dateRangeExtractor,
        CsvExportService $csvExportService,
        ChartDataService $chartDataService,
    ) {
        parent::__construct($renderer, $sensorRepo, $csrfService, $dateRangeExtractor, $csvExportService, $chartDataService);
    }

    protected function getBoard(): int
    {
        return 2;
    }

    protected function getChartColumns(): array
    {
        return [
            'TempAirInt', 'TempAirExt',
            'HumidAirInt', 'HumidAirExt',
            'LuminositeMoy', 'LuminositeA', 'LuminositeB', 'LuminositeC', 'LuminositeD',
            'HumidSol', 'TempEau', 'Pluie',
            'PontDiv', 'bootCount',
            'ServoHB', 'ServoGD', 'resetMode',
        ];
    }

    protected function getStatsColumns(): array
    {
        return [
            'TempAirInt', 'TempAirExt', 'HumidAirInt', 'HumidAirExt',
            'LuminositeMoy', 'LuminositeA', 'LuminositeB', 'LuminositeC', 'LuminositeD',
            'HumidSol', 'TempEau', 'Pluie', 'PontDiv', 'bootCount',
            'ServoHB', 'ServoGD',
        ];
    }

    protected function getTemplateName(): string
    {
        return 'msp1_data.twig';
    }
    protected function getPageTitle(string $testSuffix): string
    {
        return 'Données station météo - Le potager' . $testSuffix;
    }
    protected function getNavActive(): string
    {
        return 'potager';
    }
    protected function getCsvPrefix(): string
    {
        return 'msp1_data';
    }
    protected function getRealtimeApiBase(string $environment): string
    {
        return RealtimeUrlHelper::getMspRealtimeApiBase($environment);
    }
    protected function getTestEnvironmentName(): string
    {
        return 'msp_test';
    }

    protected function getDataConfig(string $environment): array
    {
        return [
            'hero_title' => 'Le potager – Station météo',
            'hero_icon' => 'fa-sun',
            'hero_subtitle' => 'Supervision des capteurs de la station météo (température, humidité, luminosité, eau, pluie).',
            'hero_more_url' => '/meteo-description',
            'hero_more_label' => 'En savoir plus sur le module',
            'form_action' => '/meteo',
            'test_env' => 'msp_test',
            'table_label' => $environment === 'msp_test' ? 'msp1DataTest' : 'msp1Data',
            'footer_text' => 'Station météo (Le potager)',
        ];
    }

    protected function getSensorsConfig(): array
    {
        return [
            ['key' => 'TempAirInt', 'label' => 'Temp. air int.', 'icon' => 'fa-thermometer-half', 'class' => 'temp', 'unit' => '°C', 'decimals' => 1],
            ['key' => 'TempAirExt', 'label' => 'Temp. air ext.', 'icon' => 'fa-temperature-low', 'class' => 'temp', 'unit' => '°C', 'decimals' => 1],
            ['key' => 'HumidAirInt', 'label' => 'Humid. air int.', 'icon' => 'fa-tint', 'class' => 'humidity', 'unit' => '%', 'decimals' => 0, 'unit_suffix' => '%'],
            ['key' => 'HumidAirExt', 'label' => 'Humid. air ext.', 'icon' => 'fa-cloud', 'class' => 'humidity', 'unit' => '%', 'decimals' => 0, 'unit_suffix' => '%'],
            ['key' => 'LuminositeMoy', 'label' => 'Luminosité moy.', 'icon' => 'fa-sun', 'class' => 'light', 'unit' => '', 'decimals' => 0],
            ['key' => 'LuminositeA', 'label' => 'Luminosité A', 'icon' => 'fa-sun', 'class' => 'light', 'unit' => '', 'decimals' => 0],
            ['key' => 'LuminositeB', 'label' => 'Luminosité B', 'icon' => 'fa-sun', 'class' => 'light', 'unit' => '', 'decimals' => 0],
            ['key' => 'LuminositeC', 'label' => 'Luminosité C', 'icon' => 'fa-sun', 'class' => 'light', 'unit' => '', 'decimals' => 0],
            ['key' => 'LuminositeD', 'label' => 'Luminosité D', 'icon' => 'fa-sun', 'class' => 'light', 'unit' => '', 'decimals' => 0],
            ['key' => 'TempEau', 'label' => 'Temp. eau', 'icon' => 'fa-water', 'class' => 'water', 'unit' => '°C', 'decimals' => 1],
            ['key' => 'HumidSol', 'label' => 'Humid. sol', 'icon' => 'fa-seedling', 'class' => 'humidity', 'unit' => '%', 'decimals' => 0, 'unit_suffix' => '%'],
            ['key' => 'Pluie', 'label' => 'Pluie', 'icon' => 'fa-cloud-rain', 'class' => 'rain', 'unit' => '', 'decimals' => 0],
            ['key' => 'bootCount', 'label' => 'Redémarrages', 'icon' => 'fa-sync', 'class' => 'system', 'unit' => '', 'decimals' => 0, 'no_stats' => false],
            ['key' => 'PontDiv', 'label' => 'Pont div. (batterie)', 'icon' => 'fa-battery-half', 'class' => 'system', 'unit' => '', 'decimals' => 0],
            ['key' => 'ServoHB', 'label' => 'Servo H-B', 'icon' => 'fa-cog', 'class' => 'system', 'unit' => '', 'decimals' => 0],
            ['key' => 'ServoGD', 'label' => 'Servo G-D', 'icon' => 'fa-cog', 'class' => 'system', 'unit' => '', 'decimals' => 0],
        ];
    }

    protected function getChartsConfig(): array
    {
        return [
            [
                'id' => 'chart-temperatures',
                'title' => 'Températures & Humidité',
                'icon' => 'fa-thermometer-half',
                'legend_items' => [
                    ['name' => 'Temp. int.', 'color' => '#e74c3c'],
                    ['name' => 'Temp. ext.', 'color' => '#c0392b'],
                    ['name' => 'Humid. int.', 'color' => '#3498db'],
                    ['name' => 'Humid. ext.', 'color' => '#2980b9'],
                ],
            ],
            [
                'id' => 'chart-lights',
                'title' => 'Luminosité',
                'icon' => 'fa-sun',
                'legend_items' => [
                    ['name' => 'Moy.', 'color' => '#f39c12'],
                    ['name' => 'A', 'color' => '#e67e22'],
                    ['name' => 'B', 'color' => '#d35400'],
                    ['name' => 'C', 'color' => '#f1c40f'],
                    ['name' => 'D', 'color' => '#e74c3c'],
                ],
            ],
            [
                'id' => 'chart-niveauxeaux',
                'title' => 'Humidité du sol & Eau',
                'icon' => 'fa-seedling',
                'legend_items' => [
                    ['name' => 'Humid. sol', 'color' => '#27ae60'],
                    ['name' => 'Pluie', 'color' => '#9b59b6'],
                    ['name' => 'Temp. eau', 'color' => '#008B74'],
                    ['name' => 'Reset', 'color' => '#bdc3c7'],
                ],
            ],
            [
                'id' => 'chart-cycles',
                'title' => 'Autonomie & Système',
                'icon' => 'fa-cog',
                'height' => '300px',
                'legend_items' => [
                    ['name' => 'bootCount', 'color' => '#2c3e50'],
                    ['name' => 'PontDiv', 'color' => '#e74c3c'],
                    ['name' => 'ServoHB', 'color' => '#3498db'],
                    ['name' => 'ServoGD', 'color' => '#f39c12'],
                ],
            ],
        ];
    }

    protected function getSensorMapJson(): string
    {
        return '{
            "TempAirInt":{"chartId":"chart-temperatures","seriesIndex":0},
            "TempAirExt":{"chartId":"chart-temperatures","seriesIndex":1},
            "HumidAirInt":{"chartId":"chart-temperatures","seriesIndex":2},
            "HumidAirExt":{"chartId":"chart-temperatures","seriesIndex":3},
            "LuminositeMoy":{"chartId":"chart-lights","seriesIndex":0},
            "LuminositeA":{"chartId":"chart-lights","seriesIndex":1},
            "LuminositeB":{"chartId":"chart-lights","seriesIndex":2},
            "LuminositeC":{"chartId":"chart-lights","seriesIndex":3},
            "LuminositeD":{"chartId":"chart-lights","seriesIndex":4},
            "HumidSol":{"chartId":"chart-niveauxeaux","seriesIndex":0},
            "Pluie":{"chartId":"chart-niveauxeaux","seriesIndex":1},
            "TempEau":{"chartId":"chart-niveauxeaux","seriesIndex":2},
            "resetMode":{"chartId":"chart-niveauxeaux","seriesIndex":3},
            "bootCount":{"chartId":"chart-cycles","seriesIndex":0},
            "PontDiv":{"chartId":"chart-cycles","seriesIndex":1},
            "ServoHB":{"chartId":"chart-cycles","seriesIndex":2},
            "ServoGD":{"chartId":"chart-cycles","seriesIndex":3}
        }';
    }
}
