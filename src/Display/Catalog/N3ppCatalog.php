<?php

declare(strict_types=1);

namespace App\Display\Catalog;

use App\Display\PageCatalog;

/**
 * Catalogue d'affichage de la page de suivi N3PP (serre / élevage d'insectes).
 */
final class N3ppCatalog extends PageCatalog
{
    public function pageKey(): string
    {
        return 'n3pp';
    }

    public function label(): string
    {
        return 'Serre / élevage (N3PP)';
    }

    public function chartColumns(): array
    {
        return [
            'TempAir', 'Humidite', 'Pression', 'Luminosite',
            'Humid1', 'Humid2', 'Humid3', 'Humid4', 'HumidMoy',
            'PontDiv', 'bootCount',
            'etatPompe', 'resetMode',
        ];
    }

    public function statsColumns(): array
    {
        return [
            'TempAir', 'Humidite', 'Pression', 'Luminosite',
            'Humid1', 'Humid2', 'Humid3', 'Humid4', 'HumidMoy',
            'PontDiv', 'bootCount', 'etatPompe',
        ];
    }

    public function pageConfig(string $environment): array
    {
        return [
            'hero_title' => "L'élevage d'insectes — la serre",
            'hero_icon' => 'fa-leaf',
            'hero_subtitle' => "L'habitat de nos insectes — phasmes, mantes, vers à soie et d'autres espèces, de plus en plus locales — surveillé en direct : humidité, chaleur, lumière, et arrosage automatique.",
            'hero_more_url' => '/serre-description',
            'hero_more_label' => 'En savoir plus sur le module',
            'form_action' => '/serre',
            'form_id' => 'n3ppFilterForm',
            'test_env' => 'n3pp_test',
            'table_label' => $environment === 'n3pp_test' ? 'n3ppDataTest' : 'n3ppData',
            'footer_text' => "Serre / élevage d'insectes (n3pp)",
        ];
    }

    public function monitoringUrl(string $environment): string
    {
        return $environment === 'n3pp_test' ? '/n3pp-test/n3ppdatas/n3pp-data.php' : '/serre';
    }

    protected function defineBlocks(): array
    {
        $soilProbes = [];
        $soilSeries = [];
        $probeColors = [1 => ['#3498db', [52, 152, 219]], 2 => ['#2980b9', [41, 128, 185]], 3 => ['#1abc9c', [26, 188, 156]], 4 => ['#16a085', [22, 160, 133]]];
        foreach ($probeColors as $i => [$hex, $rgb]) {
            $soilProbes[] = ['key' => "Humid{$i}", 'label' => "Humid. sol {$i}", 'icon' => 'fa-droplet', 'color' => $hex, 'unit' => 'UA', 'simple' => false];
            $soilSeries[] = [
                'column' => "Humid{$i}", 'name' => "Humid. {$i}", 'type' => 'areaspline', 'color' => $hex,
                'fill' => [...$rgb, 0.16, 0.04], 'dashStyle' => 'Dash', 'tooltip' => ['valueSuffix' => ' UA'],
                'default' => self::SERIES_LEGEND, 'simple' => self::SERIES_HIDDEN,
            ];
        }

        return [
            [
                'id' => 'sol',
                'title' => 'Humidité du sol',
                'icon' => 'fa-seedling',
                'cards' => [
                    ['key' => 'HumidMoy', 'label' => 'Humid. sol moy.', 'icon' => 'fa-seedling', 'color' => '#27ae60', 'unit' => 'UA'],
                    ...$soilProbes,
                    ['key' => 'etatPompe', 'label' => 'État pompe', 'icon' => 'fa-water', 'color' => '#008B74', 'stats' => false, 'css_class' => 'pump'],
                ],
                'chart' => [
                    'id' => 'chart-niveauxeaux',
                    'yAxis' => [['title' => ['text' => 'Humidité sol (UA)'], 'opposite' => false]],
                    'series' => [
                        ['column' => 'HumidMoy', 'name' => 'Humid. moy.', 'type' => 'areaspline', 'color' => '#27ae60', 'fill' => [39, 174, 96], 'lineWidth' => 3, 'tooltip' => ['valueSuffix' => ' UA']],
                        ['id' => 'HumidMoyTendance', 'trend_of' => 'HumidMoy', 'trend_round' => true, 'name' => 'Tendance humid. moy.', 'type' => 'line', 'color' => '#1e8449', 'dashStyle' => 'Dot', 'tooltip' => ['valueDecimals' => 0, 'valueSuffix' => ' UA']],
                        ...$soilSeries,
                        ['column' => 'etatPompe', 'name' => 'État pompe', 'type' => 'column', 'color' => '#008B74', 'opacity' => 0.5],
                        ['column' => 'resetMode', 'name' => 'Reset', 'type' => 'column', 'color' => '#bdc3c7', 'simple' => self::SERIES_HIDDEN],
                    ],
                ],
            ],
            [
                'id' => 'physique',
                'title' => 'Paramètres physiques',
                'icon' => 'fa-thermometer-half',
                'cards' => [
                    ['key' => 'TempAir', 'label' => 'Température air', 'icon' => 'fa-thermometer-half', 'color' => '#e74c3c', 'unit' => '°C', 'decimals' => 1],
                    ['key' => 'Humidite', 'label' => 'Humidité air', 'icon' => 'fa-tint', 'color' => '#3498db', 'unit' => '%'],
                    // BME280 (firmware n3pp >= 4.70) : carte affichée seulement si le capteur remonte une valeur.
                    ['key' => 'Pression', 'label' => 'Pression atm.', 'icon' => 'fa-tachometer-alt', 'color' => '#8e44ad', 'unit' => 'hPa', 'if_present' => true],
                    ['key' => 'Luminosite', 'label' => 'Luminosité', 'icon' => 'fa-sun', 'color' => '#f39c12'],
                ],
                'chart' => [
                    'id' => 'chart-temperatures',
                    'yAxis' => [
                        ['title' => ['text' => 'Température (°C)'], 'opposite' => false],
                        ['title' => ['text' => 'Humidité (%)'], 'opposite' => true],
                        ['title' => ['text' => 'Luminosité (UA)'], 'opposite' => true, 'offset' => 60],
                        ['title' => ['text' => 'Pression (hPa)'], 'opposite' => false, 'offset' => 60],
                    ],
                    'series' => [
                        ['column' => 'TempAir', 'name' => 'Temp. air', 'type' => 'areaspline', 'color' => '#e74c3c', 'fill' => [231, 76, 60], 'tooltip' => ['valueSuffix' => ' °C']],
                        ['column' => 'Humidite', 'name' => 'Humidité', 'type' => 'areaspline', 'color' => '#3498db', 'fill' => [52, 152, 219], 'yAxis' => 1, 'tooltip' => ['valueSuffix' => ' %']],
                        ['column' => 'Luminosite', 'name' => 'Luminosité', 'type' => 'areaspline', 'color' => '#f39c12', 'fill' => [243, 156, 18], 'yAxis' => 2],
                        ['column' => 'Pression', 'name' => 'Pression', 'type' => 'spline', 'color' => '#8e44ad', 'yAxis' => 3, 'tooltip' => ['valueSuffix' => ' hPa']],
                    ],
                ],
            ],
            [
                'id' => 'systeme',
                'title' => 'Système',
                'icon' => 'fa-cog',
                'simple' => false,
                'cards' => [
                    ['key' => 'bootCount', 'label' => 'Redémarrages', 'icon' => 'fa-sync', 'color' => '#2c3e50'],
                    ['key' => 'PontDiv', 'label' => 'Pont div. (batterie)', 'icon' => 'fa-battery-half', 'color' => '#e74c3c'],
                ],
                'chart' => [
                    'id' => 'chart-cycles',
                    'kind' => 'cycles',
                    'yAxis' => [
                        ['title' => ['text' => 'Cycles'], 'opposite' => false],
                        ['title' => ['text' => 'Batterie'], 'opposite' => true],
                    ],
                    'series' => [
                        ['column' => 'bootCount', 'name' => 'bootCount', 'type' => 'areaspline', 'color' => '#2c3e50', 'fill' => [44, 62, 80, 0.12, 0.03]],
                        ['column' => 'PontDiv', 'name' => 'PontDiv', 'type' => 'areaspline', 'color' => '#e74c3c', 'fill' => [231, 76, 60, 0.12, 0.03], 'yAxis' => 1],
                    ],
                ],
            ],
            [
                'id' => 'capteur',
                'type' => self::BLOCK_CAPTION,
                'title' => 'Capteur et firmware',
                'icon' => 'fa-microchip',
                'simple' => false,
            ],
            [
                'id' => 'filtrage',
                'type' => self::BLOCK_FILTER_HEALTH,
                'title' => 'Filtrage et état du système',
                'icon' => 'fa-filter',
            ],
        ];
    }
}
