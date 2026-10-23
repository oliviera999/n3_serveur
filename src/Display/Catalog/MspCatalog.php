<?php

declare(strict_types=1);

namespace App\Display\Catalog;

use App\Display\PageCatalog;

/**
 * Catalogue d'affichage de la page de suivi MSP1 (station météo « Le potager »).
 */
final class MspCatalog extends PageCatalog
{
    public function pageKey(): string
    {
        return 'msp1';
    }

    public function label(): string
    {
        return 'Station météo (Le potager)';
    }

    public function chartColumns(): array
    {
        return [
            'TempAirInt', 'TempAirExt',
            'HumidAirInt', 'HumidAirExt', 'Pression',
            'LuminositeMoy', 'LuminositeA', 'LuminositeB', 'LuminositeC', 'LuminositeD',
            'HumidSol', 'TempEau', 'Pluie',
            'PontDiv', 'bootCount',
            'ServoHB', 'ServoGD', 'resetMode',
        ];
    }

    public function statsColumns(): array
    {
        return [
            'TempAirInt', 'TempAirExt', 'HumidAirInt', 'HumidAirExt', 'Pression',
            'LuminositeMoy', 'LuminositeA', 'LuminositeB', 'LuminositeC', 'LuminositeD',
            'HumidSol', 'TempEau', 'Pluie', 'PontDiv', 'bootCount',
            'ServoHB', 'ServoGD',
        ];
    }

    public function pageConfig(string $environment): array
    {
        return [
            'hero_title' => 'Le potager — station météo',
            'hero_icon' => 'fa-sun',
            'hero_subtitle' => 'La météo du jardin, mesurée en continu : soleil, chaleur, pluie, et l\'eau dans le sol.',
            'hero_more_url' => '/meteo-description',
            'hero_more_label' => 'En savoir plus sur le module',
            'form_action' => '/meteo',
            'form_id' => 'msp1FilterForm',
            'test_env' => 'msp_test',
            'table_label' => $environment === 'msp_test' ? 'msp1DataTest' : 'msp1Data',
            'footer_text' => 'Station météo (Le potager)',
        ];
    }

    public function monitoringUrl(string $environment): string
    {
        return $environment === 'msp_test' ? '/msp1-test/msp1datas/msp1-data.php' : '/meteo';
    }

    protected function defineBlocks(): array
    {
        return [
            [
                'id' => 'luminosite',
                'title' => 'Luminosité',
                'icon' => 'fa-sun',
                'cards' => [
                    ['key' => 'LuminositeMoy', 'label' => 'Luminosité moy.', 'icon' => 'fa-sun', 'color' => '#f39c12'],
                    ['key' => 'LuminositeA', 'label' => 'A', 'icon' => 'fa-sun', 'color' => '#e67e22', 'simple' => false],
                    ['key' => 'LuminositeB', 'label' => 'B', 'icon' => 'fa-sun', 'color' => '#d35400', 'simple' => false],
                    ['key' => 'LuminositeC', 'label' => 'C', 'icon' => 'fa-sun', 'color' => '#f1c40f', 'simple' => false],
                    ['key' => 'LuminositeD', 'label' => 'D', 'icon' => 'fa-sun', 'color' => '#e74c3c', 'simple' => false],
                ],
                'chart' => [
                    'id' => 'chart-lights',
                    'series' => [
                        ['column' => 'LuminositeMoy', 'name' => 'Moy.', 'type' => 'areaspline', 'color' => '#f39c12', 'fill' => [243, 156, 18, 0.22, 0.05], 'lineWidth' => 3],
                        ['column' => 'LuminositeA', 'name' => 'A', 'type' => 'areaspline', 'color' => '#e67e22', 'fill' => [230, 126, 34, 0.14, 0.04], 'dashStyle' => 'Dash', 'simple' => 'hidden'],
                        ['column' => 'LuminositeB', 'name' => 'B', 'type' => 'areaspline', 'color' => '#d35400', 'fill' => [211, 84, 0, 0.14, 0.04], 'dashStyle' => 'Dash', 'simple' => 'hidden'],
                        ['column' => 'LuminositeC', 'name' => 'C', 'type' => 'areaspline', 'color' => '#f1c40f', 'fill' => [241, 196, 15, 0.14, 0.04], 'dashStyle' => 'Dash', 'simple' => 'hidden'],
                        ['column' => 'LuminositeD', 'name' => 'D', 'type' => 'areaspline', 'color' => '#e74c3c', 'fill' => [231, 76, 60, 0.14, 0.04], 'dashStyle' => 'Dash', 'simple' => 'hidden'],
                    ],
                ],
            ],
            [
                'id' => 'sol',
                'title' => 'Paramètres du sol',
                'icon' => 'fa-seedling',
                'cards' => [
                    ['key' => 'HumidSol', 'label' => 'Humidité sol', 'icon' => 'fa-seedling', 'color' => '#27ae60', 'unit' => '%'],
                    ['key' => 'Pluie', 'label' => 'Pluie', 'icon' => 'fa-cloud-rain', 'color' => '#9b59b6'],
                    ['key' => 'TempEau', 'label' => 'Température eau', 'icon' => 'fa-water', 'color' => '#008B74', 'unit' => '°C', 'decimals' => 1],
                ],
                'chart' => [
                    'id' => 'chart-niveauxeaux',
                    'yAxis' => [
                        ['title' => ['text' => 'Humidité sol / Pluie'], 'opposite' => false],
                        ['title' => ['text' => 'Temp. eau (°C)'], 'opposite' => true],
                    ],
                    'series' => [
                        ['column' => 'HumidSol', 'name' => 'Humid. sol', 'type' => 'areaspline', 'color' => '#27ae60', 'fill' => [39, 174, 96]],
                        ['column' => 'Pluie', 'name' => 'Pluie', 'type' => 'column', 'color' => '#9b59b6'],
                        ['column' => 'TempEau', 'name' => 'Temp. eau', 'type' => 'areaspline', 'color' => '#008B74', 'fill' => [0, 139, 116], 'yAxis' => 1, 'tooltip' => ['valueSuffix' => ' °C']],
                        ['column' => 'resetMode', 'name' => 'Reset', 'type' => 'column', 'color' => '#bdc3c7', 'simple' => 'hidden'],
                    ],
                ],
            ],
            [
                'id' => 'physique',
                'title' => 'Paramètres physiques',
                'icon' => 'fa-thermometer-half',
                'cards' => [
                    ['key' => 'TempAirInt', 'label' => 'Temp. air int.', 'icon' => 'fa-thermometer-half', 'color' => '#e74c3c', 'unit' => '°C', 'decimals' => 1],
                    ['key' => 'TempAirExt', 'label' => 'Temp. air ext.', 'icon' => 'fa-temperature-low', 'color' => '#c0392b', 'unit' => '°C', 'decimals' => 1],
                    ['key' => 'HumidAirInt', 'label' => 'Humid. air int.', 'icon' => 'fa-tint', 'color' => '#3498db', 'unit' => '%'],
                    ['key' => 'HumidAirExt', 'label' => 'Humid. air ext.', 'icon' => 'fa-cloud', 'color' => '#2980b9', 'unit' => '%'],
                    // BME280 (firmware msp >= 2.74) : carte affichée seulement si le capteur remonte une valeur.
                    ['key' => 'Pression', 'label' => 'Pression atm.', 'icon' => 'fa-tachometer-alt', 'color' => '#8e44ad', 'unit' => 'hPa', 'if_present' => true],
                ],
                'chart' => [
                    'id' => 'chart-temperatures',
                    'yAxis' => [
                        ['title' => ['text' => 'Température (°C)'], 'opposite' => false],
                        ['title' => ['text' => 'Humidité (%)'], 'opposite' => true],
                        ['title' => ['text' => 'Pression (hPa)'], 'opposite' => true, 'offset' => 60],
                    ],
                    'series' => [
                        ['column' => 'TempAirInt', 'name' => 'Temp. int.', 'type' => 'areaspline', 'color' => '#e74c3c', 'fill' => [231, 76, 60], 'tooltip' => ['valueSuffix' => ' °C']],
                        ['column' => 'TempAirExt', 'name' => 'Temp. ext.', 'type' => 'areaspline', 'color' => '#c0392b', 'fill' => [192, 57, 43], 'tooltip' => ['valueSuffix' => ' °C']],
                        ['column' => 'HumidAirInt', 'name' => 'Humid. int.', 'type' => 'areaspline', 'color' => '#3498db', 'fill' => [52, 152, 219], 'yAxis' => 1, 'tooltip' => ['valueSuffix' => ' %']],
                        ['column' => 'HumidAirExt', 'name' => 'Humid. ext.', 'type' => 'areaspline', 'color' => '#2980b9', 'fill' => [41, 128, 185], 'yAxis' => 1, 'tooltip' => ['valueSuffix' => ' %']],
                        ['column' => 'Pression', 'name' => 'Pression', 'type' => 'spline', 'color' => '#8e44ad', 'yAxis' => 2, 'tooltip' => ['valueSuffix' => ' hPa']],
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
                    ['key' => 'ServoHB', 'label' => 'Servo H-B', 'icon' => 'fa-cog', 'color' => '#3498db'],
                    ['key' => 'ServoGD', 'label' => 'Servo G-D', 'icon' => 'fa-cog', 'color' => '#f39c12'],
                ],
                'chart' => [
                    'id' => 'chart-cycles',
                    'kind' => 'cycles',
                    'yAxis' => [
                        ['title' => ['text' => 'Cycles / Servos'], 'opposite' => false],
                        ['title' => ['text' => 'Batterie'], 'opposite' => true],
                    ],
                    'series' => [
                        ['column' => 'bootCount', 'name' => 'bootCount', 'type' => 'areaspline', 'color' => '#2c3e50', 'fill' => [44, 62, 80, 0.12, 0.03]],
                        ['column' => 'PontDiv', 'name' => 'PontDiv', 'type' => 'areaspline', 'color' => '#e74c3c', 'fill' => [231, 76, 60, 0.12, 0.03], 'yAxis' => 1],
                        ['column' => 'ServoHB', 'name' => 'ServoHB', 'type' => 'areaspline', 'color' => '#3498db', 'fill' => [52, 152, 219, 0.12, 0.03]],
                        ['column' => 'ServoGD', 'name' => 'ServoGD', 'type' => 'areaspline', 'color' => '#f39c12', 'fill' => [243, 156, 18, 0.12, 0.03]],
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
            [
                'id' => 'mesures-manuelles',
                'type' => self::BLOCK_GOOGLE_SHEET,
                'title' => 'Mesures manuelles',
                'icon' => 'fa-flask',
                'simple' => false,
                'options' => [
                    'card_title' => 'Graphique des mesures',
                    'src' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vT5i0I15n-Nef5J1LlY-MGYkPNQtmyzJJ08yObEc4dI_YCQrHFyEwxBx6vmwX-6MnqHwHVzDvupD_Qv/pubchart?oid=583144182&format=interactive',
                    'sheet_title' => 'Mesures manuelles MSP1',
                ],
            ],
        ];
    }
}
