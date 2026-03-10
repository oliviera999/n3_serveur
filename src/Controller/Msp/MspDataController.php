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

    protected function getBoard(): int { return 2; }

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
        ];
    }

    protected function getTemplateName(): string { return 'msp1_data.twig'; }
    protected function getPageTitle(string $testSuffix): string { return 'Données station météo - Le potager' . $testSuffix; }
    protected function getNavActive(): string { return 'potager'; }
    protected function getCsvPrefix(): string { return 'msp1_data'; }
    protected function getRealtimeApiBase(string $environment): string { return RealtimeUrlHelper::getMspRealtimeApiBase($environment); }
    protected function getTestEnvironmentName(): string { return 'msp_test'; }

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
            ['key' => 'TempEau', 'label' => 'Temp. eau', 'icon' => 'fa-water', 'class' => 'water', 'unit' => '°C', 'decimals' => 1],
            ['key' => 'HumidSol', 'label' => 'Humid. sol', 'icon' => 'fa-seedling', 'class' => 'humidity', 'unit' => '%', 'decimals' => 0, 'unit_suffix' => '%'],
            ['key' => 'Pluie', 'label' => 'Pluie', 'icon' => 'fa-cloud-rain', 'class' => 'rain', 'unit' => '', 'decimals' => 0],
        ];
    }

    protected function getChartsConfig(): array
    {
        return [
            [
                'id' => 'chart-temperatures',
                'title' => 'Températures & Humidité',
                'icon' => 'fa-thermometer-half',
                'legend' => 'Temp. int./ext. : températures intérieure et extérieure (°C). Humid. int./ext. : humidité relative de l\'air (%) mesurée en intérieur et extérieur.',
            ],
            [
                'id' => 'chart-lights',
                'title' => 'Luminosité',
                'icon' => 'fa-sun',
                'legend' => 'Moy. : luminosité moyenne. A, B, C, D : capteurs de luminosité aux quatre zones de la serre météo (en lux ou unités arbitraires).',
            ],
            [
                'id' => 'chart-niveauxeaux',
                'title' => 'Humidité du sol & Eau',
                'icon' => 'fa-seedling',
                'legend' => 'Humid. sol : humidité du substrat (%). Pluie : indicateur de pluie. Temp. eau : température de l\'eau du système (°C). Reset : redémarrage du firmware.',
            ],
            [
                'id' => 'chart-cycles',
                'title' => 'Autonomie & Système',
                'icon' => 'fa-cog',
                'height' => '300px',
                'legend' => 'bootCount : nombre de redémarrages. PontDiv : tension de la batterie (diviseur de pont). ServoHB / ServoGD : cycles des servomoteurs haut-bas et gauche-droite.',
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
