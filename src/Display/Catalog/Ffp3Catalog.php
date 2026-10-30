<?php

declare(strict_types=1);

namespace App\Display\Catalog;

use App\Display\PageCatalog;

/**
 * Catalogue d'affichage de la page de suivi aquaponie FFP3.
 *
 * Les niveaux d'eau sont exprimés en cm côté page (conversion mm→cm dans le contrôleur).
 * Blocs spécifiques : tide_panel, water_balance (partials dédiés).
 */
final class Ffp3Catalog extends PageCatalog
{
    public function pageKey(): string
    {
        return 'ffp3';
    }

    public function label(): string
    {
        return 'Aquaponie (FFP3)';
    }

    public function chartColumns(): array
    {
        return [
            'EauAquarium', 'EauReserve', 'EauPotager',
            'TempEau', 'TempAir', 'Humidite', 'Luminosite',
            'etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV',
            'bouffePetits', 'bouffeGros',
        ];
    }

    public function statsColumns(): array
    {
        return [
            'EauAquarium', 'EauReserve', 'EauPotager',
            'TempEau', 'TempAir', 'Humidite', 'Luminosite',
        ];
    }

    public function pageConfig(string $environment): array
    {
        $formAction = match ($environment) {
            'test' => '/aquaponie-test',
            'test3' => '/aquamobile-test',
            's3', 's3test' => '/aquamobile',
            default => '/aquaponie',
        };

        return [
            'hero_title' => "L'aquaponie",
            'hero_icon_left' => 'fa-seedling',
            'hero_icon_right' => 'fa-fish',
            'hero_subtitle' => 'Tout ce que la ferme aquaponique mesure et fait, en direct : capteurs, pompes, marées et bilan d\'eau.',
            'hero_more_url' => '/aquaponie-description',
            'hero_more_label' => 'En savoir plus sur le module',
            'form_action' => $formAction,
            'form_id' => 'ffp3FilterForm',
            'test_env' => 'test',
            'table_label' => match ($environment) {
                'test' => 'ffp3Data2',
                'test3' => 'ffp3Data3',
                's3' => 'ffp3DataS3',
                's3test' => 'ffp3DataS3Test',
                default => 'ffp3Data',
            },
            'footer_text' => 'Aquaponie FFP3',
        ];
    }

    public function monitoringUrl(string $environment): string
    {
        return match ($environment) {
            'test' => '/aquaponie-test',
            'test3' => '/aquamobile-test',
            's3', 's3test' => '/aquamobile',
            default => '/aquaponie',
        };
    }

    protected function defineBlocks(): array
    {
        return [
            [
                'id' => 'intro',
                'type' => self::BLOCK_CUSTOM,
                'title' => 'En un coup d\'œil',
                'icon' => 'fa-info-circle',
                'simple' => true,
                'options' => ['template' => 'partials/blocks/custom/_ffp3_intro.twig'],
            ],
            [
                'id' => 'niveaux',
                'title' => 'Niveaux d\'eau',
                'icon' => 'fa-tint',
                'cards' => [
                    ['key' => 'EauAquarium', 'label' => 'Aquarium', 'icon' => 'fa-fish', 'color' => '#3498db', 'unit' => 'cm', 'decimals' => 1, 'css_class' => 'aquarium'],
                    ['key' => 'EauReserve', 'label' => 'Réserve', 'icon' => 'fa-droplet', 'color' => '#2980b9', 'unit' => 'cm', 'decimals' => 1, 'css_class' => 'reserve'],
                    ['key' => 'EauPotager', 'label' => 'Potager', 'icon' => 'fa-seedling', 'color' => '#27ae60', 'unit' => 'cm', 'decimals' => 1, 'css_class' => 'potager'],
                ],
                'chart' => [
                    'id' => 'chart-ffp3-eau',
                    'yAxis' => [
                        ['title' => ['text' => 'Niveau (cm)'], 'opposite' => false],
                        ['title' => ['text' => 'État'], 'opposite' => true, 'min' => 0, 'max' => 1, 'visible' => false],
                    ],
                    'tooltip' => ['valueDecimals' => 1, 'valueSuffix' => ' cm'],
                    'series' => [
                        ['column' => 'EauAquarium', 'name' => 'Aquarium', 'type' => 'areaspline', 'color' => '#3498db', 'fill' => [52, 152, 219, 0.25, 0.05], 'lineWidth' => 3],
                        ['column' => 'EauReserve', 'name' => 'Réserve', 'type' => 'areaspline', 'color' => '#2980b9', 'fill' => [41, 128, 185, 0.18, 0.04]],
                        ['column' => 'EauPotager', 'name' => 'Potager', 'type' => 'areaspline', 'color' => '#27ae60', 'fill' => [39, 174, 96, 0.18, 0.04]],
                        ['column' => 'etatPompeAqua', 'name' => 'Pompe aqua', 'type' => 'line', 'color' => '#8e44ad', 'yAxis' => 1, 'simple' => 'legend', 'tooltip' => ['valueDecimals' => 0]],
                        ['column' => 'etatPompeTank', 'name' => 'Pompe réserve', 'type' => 'line', 'color' => '#9b59b6', 'yAxis' => 1, 'simple' => 'hidden', 'tooltip' => ['valueDecimals' => 0]],
                        ['id' => 'trend-EauAquarium', 'trend_of' => 'EauAquarium', 'name' => 'Tendance aquarium', 'type' => 'line', 'color' => '#1a5276', 'dashStyle' => 'ShortDash', 'lineWidth' => 1, 'simple' => 'legend'],
                    ],
                ],
            ],
            [
                'id' => 'physique',
                'title' => 'Paramètres physiques',
                'icon' => 'fa-thermometer-half',
                'cards' => [
                    ['key' => 'TempEau', 'label' => 'Temp. eau', 'icon' => 'fa-water', 'color' => '#008B74', 'unit' => '°C', 'decimals' => 1, 'css_class' => 'tempeau'],
                    ['key' => 'TempAir', 'label' => 'Temp. air', 'icon' => 'fa-temperature-high', 'color' => '#e67e22', 'unit' => '°C', 'decimals' => 1],
                    ['key' => 'Humidite', 'label' => 'Humidité', 'icon' => 'fa-cloud', 'color' => '#3498db', 'unit' => '%', 'decimals' => 0, 'css_class' => 'humidity'],
                    ['key' => 'Luminosite', 'label' => 'Luminosité', 'icon' => 'fa-sun', 'color' => '#f39c12', 'simple' => false],
                ],
                'chart' => [
                    'id' => 'chart-ffp3-temp',
                    'yAxis' => [
                        ['title' => ['text' => 'Température (°C)'], 'opposite' => false],
                        ['title' => ['text' => 'Humidité / lum.'], 'opposite' => true],
                    ],
                    'tooltip' => ['shared' => true],
                    'series' => [
                        ['column' => 'TempEau', 'name' => 'Temp. eau', 'type' => 'spline', 'color' => '#008B74', 'lineWidth' => 3],
                        ['column' => 'TempAir', 'name' => 'Temp. air', 'type' => 'spline', 'color' => '#e67e22'],
                        ['column' => 'Humidite', 'name' => 'Humidité', 'type' => 'areaspline', 'color' => '#3498db', 'yAxis' => 1, 'fill' => [52, 152, 219, 0.15, 0.03], 'tooltip' => ['valueSuffix' => ' %']],
                        ['column' => 'Luminosite', 'name' => 'Luminosité', 'type' => 'spline', 'color' => '#f39c12', 'yAxis' => 1, 'simple' => 'legend', 'dashStyle' => 'Dash'],
                        ['column' => 'etatHeat', 'name' => 'Chauffage', 'type' => 'line', 'color' => '#c0392b', 'simple' => 'hidden', 'tooltip' => ['valueDecimals' => 0]],
                        ['column' => 'etatUV', 'name' => 'UV', 'type' => 'line', 'color' => '#8e44ad', 'simple' => 'hidden', 'tooltip' => ['valueDecimals' => 0]],
                    ],
                ],
            ],
            [
                'id' => 'marees',
                'type' => self::BLOCK_TIDE_PANEL,
                'title' => 'Cycles de marée',
                'icon' => 'fa-water',
                'simple' => true,
            ],
            [
                'id' => 'bilan',
                'type' => self::BLOCK_WATER_BALANCE,
                'title' => 'Bilan hydrique',
                'icon' => 'fa-balance-scale',
                'simple' => false,
            ],
            [
                'id' => 'actuators',
                'title' => 'Actionneurs',
                'icon' => 'fa-toggle-on',
                'simple' => false,
                'cards' => [
                    ['key' => 'etatPompeAqua', 'label' => 'Pompe aqua', 'icon' => 'fa-water', 'color' => '#8e44ad', 'stats' => false, 'decimals' => 0, 'css_class' => 'pump'],
                    ['key' => 'etatPompeTank', 'label' => 'Pompe réserve', 'icon' => 'fa-droplet', 'color' => '#9b59b6', 'stats' => false, 'decimals' => 0, 'css_class' => 'pump'],
                    ['key' => 'etatHeat', 'label' => 'Chauffage', 'icon' => 'fa-fire', 'color' => '#c0392b', 'stats' => false, 'decimals' => 0],
                    ['key' => 'etatUV', 'label' => 'UV', 'icon' => 'fa-lightbulb', 'color' => '#8e44ad', 'stats' => false, 'decimals' => 0],
                    ['key' => 'bouffePetits', 'label' => 'Bouffe petits', 'icon' => 'fa-fish', 'color' => '#16a085', 'stats' => false, 'decimals' => 0, 'simple' => false],
                    ['key' => 'bouffeGros', 'label' => 'Bouffe gros', 'icon' => 'fa-fish', 'color' => '#1abc9c', 'stats' => false, 'decimals' => 0, 'simple' => false],
                ],
                'chart' => [
                    'id' => 'chart-ffp3-actuators',
                    'kind' => 'cycles',
                    'yAxis' => [['title' => ['text' => 'État (0/1)'], 'min' => 0, 'max' => 1, 'opposite' => false]],
                    'series' => [
                        ['column' => 'etatPompeAqua', 'name' => 'Pompe aqua', 'type' => 'line', 'color' => '#8e44ad', 'step' => true],
                        ['column' => 'etatPompeTank', 'name' => 'Pompe réserve', 'type' => 'line', 'color' => '#9b59b6'],
                        ['column' => 'etatHeat', 'name' => 'Chauffage', 'type' => 'line', 'color' => '#c0392b'],
                        ['column' => 'etatUV', 'name' => 'UV', 'type' => 'line', 'color' => '#8e44ad', 'simple' => 'legend'],
                        ['column' => 'bouffePetits', 'name' => 'Bouffe petits', 'type' => 'line', 'color' => '#16a085', 'simple' => 'hidden'],
                        ['column' => 'bouffeGros', 'name' => 'Bouffe gros', 'type' => 'line', 'color' => '#1abc9c', 'simple' => 'hidden'],
                    ],
                ],
            ],
            [
                'id' => 'filtre',
                'type' => self::BLOCK_FILTER_HEALTH,
                'title' => 'Filtrage & période',
                'icon' => 'fa-filter',
                'simple' => true,
            ],
        ];
    }
}
