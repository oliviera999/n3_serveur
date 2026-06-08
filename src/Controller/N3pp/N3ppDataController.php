<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Controller\AbstractDataController;
use App\Repository\N3ppSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\TemplateRenderer;
use App\Util\RealtimeUrlHelper;

/**
 * Page de données de la serre / élevage (N3PP).
 * Hérite du flux commun AbstractDataController.
 */
class N3ppDataController extends AbstractDataController
{
    public function __construct(
        TemplateRenderer $renderer,
        N3ppSensorRepository $sensorRepo,
        CsrfService $csrfService,
        DateRangeExtractor $dateRangeExtractor,
        CsvExportService $csvExportService,
        ChartDataService $chartDataService,
    ) {
        parent::__construct($renderer, $sensorRepo, $csrfService, $dateRangeExtractor, $csvExportService, $chartDataService);
    }

    protected function getBoard(): int
    {
        return 3;
    }

    protected function getChartColumns(): array
    {
        return [
            'TempAir', 'Humidite', 'Luminosite',
            'Humid1', 'Humid2', 'Humid3', 'Humid4', 'HumidMoy',
            'PontDiv', 'bootCount',
            'etatPompe', 'resetMode',
        ];
    }

    protected function getStatsColumns(): array
    {
        return [
            'TempAir', 'Humidite', 'Luminosite',
            'Humid1', 'Humid2', 'Humid3', 'Humid4', 'HumidMoy',
            'PontDiv', 'bootCount', 'etatPompe',
        ];
    }

    protected function getTemplateName(): string
    {
        return 'n3pp_data.twig';
    }
    protected function getPageTitle(string $testSuffix): string
    {
        return 'Données serre / élevage - n3 iot' . $testSuffix;
    }
    protected function getNavActive(): string
    {
        return 'elevage';
    }
    protected function getCsvPrefix(): string
    {
        return 'n3pp_data';
    }
    protected function getRealtimeApiBase(string $environment): string
    {
        return RealtimeUrlHelper::getN3ppRealtimeApiBase($environment);
    }
    protected function getTestEnvironmentName(): string
    {
        return 'n3pp_test';
    }

    protected function getDataConfig(string $environment): array
    {
        return [
            'hero_title' => "L'élevage d'insectes – Serre",
            'hero_icon' => 'fa-leaf',
            'hero_subtitle' => 'Supervision des capteurs de la serre (température, humidité air et sol, luminosité, état pompe).',
            'hero_more_url' => '/serre-description',
            'hero_more_label' => 'En savoir plus sur le module',
            'form_action' => '/serre',
            'test_env' => 'n3pp_test',
            'table_label' => $environment === 'n3pp_test' ? 'n3ppDataTest' : 'n3ppData',
            'footer_text' => "Serre / élevage d'insectes (n3pp)",
        ];
    }

    protected function getSensorsConfig(): array
    {
        return [
            ['key' => 'TempAir', 'label' => 'Temp. air', 'icon' => 'fa-thermometer-half', 'class' => 'temp', 'unit' => '°C', 'decimals' => 1],
            ['key' => 'Humidite', 'label' => 'Humidité air', 'icon' => 'fa-tint', 'class' => 'humidity', 'unit' => '%', 'decimals' => 0, 'unit_suffix' => '%'],
            ['key' => 'Luminosite', 'label' => 'Luminosité', 'icon' => 'fa-sun', 'class' => 'light', 'unit' => '', 'decimals' => 0],
            ['key' => 'HumidMoy', 'label' => 'Humid. sol moy.', 'icon' => 'fa-seedling', 'class' => 'humidity', 'unit' => 'UA', 'decimals' => 0, 'unit_suffix' => ' UA'],
            ['icon' => 'fa-droplet', 'class' => 'humidity', 'unit' => 'UA', 'decimals' => 0, 'unit_suffix' => ' UA', 'loop' => ['from' => 1, 'to' => 4, 'prefix' => 'Humid', 'label_prefix' => 'Humid. sol']],
            ['key' => 'etatPompe', 'label' => 'État pompe', 'icon' => 'fa-water', 'class' => 'pump', 'unit' => '', 'decimals' => 0, 'no_stats' => true],
            ['key' => 'bootCount', 'label' => 'Redémarrages', 'icon' => 'fa-sync', 'class' => 'system', 'unit' => '', 'decimals' => 0],
            ['key' => 'PontDiv', 'label' => 'Pont div. (batterie)', 'icon' => 'fa-battery-half', 'class' => 'system', 'unit' => '', 'decimals' => 0],
        ];
    }

    protected function getChartsConfig(): array
    {
        return [
            [
                'id' => 'chart-niveauxeaux',
                'title' => 'Humidité du sol',
                'icon' => 'fa-seedling',
                'legend_items' => [
                    ['name' => 'Humid. moy.', 'color' => '#27ae60'],
                    ['name' => 'Humid. 1', 'color' => '#3498db'],
                    ['name' => 'Humid. 2', 'color' => '#2980b9'],
                    ['name' => 'Humid. 3', 'color' => '#1abc9c'],
                    ['name' => 'Humid. 4', 'color' => '#16a085'],
                    ['name' => 'État pompe', 'color' => '#008B74'],
                    ['name' => 'Reset', 'color' => '#bdc3c7'],
                ],
            ],
            [
                'id' => 'chart-temperatures',
                'title' => 'Température, Humidité air & Luminosité',
                'icon' => 'fa-thermometer-half',
                'legend_items' => [
                    ['name' => 'Temp. air', 'color' => '#e74c3c'],
                    ['name' => 'Humidité', 'color' => '#3498db'],
                    ['name' => 'Luminosité', 'color' => '#f39c12'],
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
                ],
            ],
        ];
    }

    protected function getSensorMapJson(): string
    {
        return '{
            "HumidMoy":{"chartId":"chart-niveauxeaux","seriesIndex":0},
            "Humid1":{"chartId":"chart-niveauxeaux","seriesIndex":2},
            "Humid2":{"chartId":"chart-niveauxeaux","seriesIndex":3},
            "Humid3":{"chartId":"chart-niveauxeaux","seriesIndex":4},
            "Humid4":{"chartId":"chart-niveauxeaux","seriesIndex":5},
            "etatPompe":{"chartId":"chart-niveauxeaux","seriesIndex":6},
            "resetMode":{"chartId":"chart-niveauxeaux","seriesIndex":7},
            "TempAir":{"chartId":"chart-temperatures","seriesIndex":0},
            "Humidite":{"chartId":"chart-temperatures","seriesIndex":1},
            "Luminosite":{"chartId":"chart-temperatures","seriesIndex":2},
            "bootCount":{"chartId":"chart-cycles","seriesIndex":0},
            "PontDiv":{"chartId":"chart-cycles","seriesIndex":1}
        }';
    }
}
