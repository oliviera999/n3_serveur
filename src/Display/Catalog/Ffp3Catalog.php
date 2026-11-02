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
        // Couleurs historiques de la page aquaponie (≤ 6.45.0) : aquarium orange, réserve et potager verts.
        $aquarium = '#FF6300';
        $reserve = '#008B74';
        $potager = '#27BDA0';
        // Actionneurs (marqueurs « ON » et chronogramme) : une couleur et un symbole chacun.
        $pompeAqua = '#8e44ad';
        $pompeTank = '#2c3e50';
        $chauffage = '#c0392b';
        $lumiere = '#f1c40f';
        $nourPetits = '#9b59b6';
        $nourGros = '#d35400';
        // Axe d'événements masqué (0–1) : les marqueurs « ON » s'y posent en bas du graphique,
        // sans déformer l'axe des mesures (cf. bande 5 % de l'ancienne page).
        $eventsAxis = ['title' => ['text' => ''], 'visible' => false, 'min' => 0, 'max' => 1, 'opposite' => true];
        $actuatorLanes = ['Pompe aquarium', 'Pompe réserve', 'Chauffage', 'Lumière', 'Nourriture petits', 'Nourriture gros'];
        $sheetBase = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTrF6mLbo5-qLN-uhd7J2LeeV7LvE9LEr9nkyXgh7KUUh7lL-Houdx3XpIQ_-3vJk50e7dTuUAvQ-dR/pubchart?oid=';

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
                    ['key' => 'EauAquarium', 'label' => 'Aquarium', 'icon' => 'fa-fish', 'color' => $aquarium, 'unit' => 'cm', 'decimals' => 1, 'css_class' => 'aquarium'],
                    ['key' => 'EauReserve', 'label' => 'Réserve', 'icon' => 'fa-droplet', 'color' => $reserve, 'unit' => 'cm', 'decimals' => 1, 'css_class' => 'reserve'],
                    ['key' => 'EauPotager', 'label' => 'Potager', 'icon' => 'fa-seedling', 'color' => $potager, 'unit' => 'cm', 'decimals' => 1, 'css_class' => 'potager'],
                ],
                'chart' => [
                    'id' => 'chart-ffp3-eau',
                    'yAxis' => [
                        ['title' => ['text' => 'Niveaux eau (cm)'], 'opposite' => false],
                        $eventsAxis,
                    ],
                    'tooltip' => ['valueDecimals' => 1, 'valueSuffix' => ' cm'],
                    'series' => [
                        ['column' => 'EauAquarium', 'name' => 'Aquarium', 'type' => 'areaspline', 'color' => $aquarium, 'fill' => [255, 99, 0, 0.3, 0.05], 'lineWidth' => 2.5],
                        // Réserve en légende (décochée) par défaut, comme avant : son niveau élevé écrasait l'amplitude des marées.
                        ['column' => 'EauReserve', 'name' => 'Réserve', 'type' => 'areaspline', 'color' => $reserve, 'fill' => [0, 126, 97, 0.3, 0.05], 'dashStyle' => 'ShortDot', 'default' => self::SERIES_LEGEND],
                        ['column' => 'EauPotager', 'name' => 'Potager', 'type' => 'areaspline', 'color' => $potager, 'fill' => [39, 189, 160, 0.3, 0.05], 'lineWidth' => 1.5],
                        ['id' => 'trend-EauAquarium', 'trend_of' => 'EauAquarium', 'name' => 'Tendance aquarium', 'type' => 'line', 'color' => '#b34700', 'dashStyle' => 'ShortDash', 'lineWidth' => 1, 'default' => self::SERIES_LEGEND],
                        ['column' => 'etatPompeAqua', 'name' => 'Pompe aquarium ON', 'color' => $pompeAqua, 'yAxis' => 1, 'on_markers' => ['lane' => 0.03, 'symbol' => 'circle', 'radius' => 3], 'simple' => self::SERIES_LEGEND],
                        ['column' => 'etatPompeTank', 'name' => 'Pompe réserve ON', 'color' => $pompeTank, 'yAxis' => 1, 'on_markers' => ['lane' => 0.07, 'symbol' => 'square', 'radius' => 3], 'simple' => self::SERIES_HIDDEN],
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
                    ['key' => 'Luminosite', 'label' => 'Luminosité', 'icon' => 'fa-sun', 'color' => '#f39c12', 'unit' => 'UA', 'simple' => false],
                ],
                'chart' => [
                    'id' => 'chart-ffp3-temp',
                    // Un axe par grandeur (comme ≤ 6.45.0) : °C, %, UA, + axe d'événements masqué.
                    'yAxis' => [
                        ['title' => ['text' => 'Température (°C)'], 'opposite' => false],
                        ['title' => ['text' => 'Humidité (%)'], 'opposite' => true],
                        ['title' => ['text' => 'Luminosité (UA)'], 'opposite' => true, 'offset' => 60],
                        $eventsAxis,
                    ],
                    'tooltip' => ['shared' => true],
                    'series' => [
                        ['column' => 'TempEau', 'name' => 'Temp. eau', 'type' => 'spline', 'color' => '#008B74', 'lineWidth' => 3, 'tooltip' => ['valueDecimals' => 1, 'valueSuffix' => ' °C']],
                        ['column' => 'TempAir', 'name' => 'Temp. air', 'type' => 'spline', 'color' => '#e67e22', 'tooltip' => ['valueDecimals' => 1, 'valueSuffix' => ' °C']],
                        ['column' => 'Humidite', 'name' => 'Humidité', 'type' => 'areaspline', 'color' => '#3498db', 'yAxis' => 1, 'fill' => [52, 152, 219, 0.15, 0.03], 'tooltip' => ['valueDecimals' => 0, 'valueSuffix' => ' %']],
                        ['column' => 'Luminosite', 'name' => 'Luminosité', 'type' => 'spline', 'color' => '#f39c12', 'yAxis' => 2, 'dashStyle' => 'Dash', 'simple' => self::SERIES_LEGEND, 'tooltip' => ['valueDecimals' => 0, 'valueSuffix' => ' UA']],
                        ['column' => 'etatHeat', 'name' => 'Chauffage ON', 'color' => $chauffage, 'yAxis' => 3, 'on_markers' => ['lane' => 0.03, 'symbol' => 'triangle', 'radius' => 4], 'simple' => self::SERIES_HIDDEN],
                        ['column' => 'etatUV', 'name' => 'Lumière ON', 'color' => $lumiere, 'yAxis' => 3, 'on_markers' => ['lane' => 0.07, 'symbol' => 'circle', 'radius' => 3], 'simple' => self::SERIES_HIDDEN],
                        ['column' => 'bouffePetits', 'name' => 'Nourriture petits', 'color' => $nourPetits, 'yAxis' => 3, 'on_markers' => ['lane' => 0.11, 'symbol' => 'diamond', 'radius' => 4], 'simple' => self::SERIES_HIDDEN],
                        ['column' => 'bouffeGros', 'name' => 'Nourriture gros', 'color' => $nourGros, 'yAxis' => 3, 'on_markers' => ['lane' => 0.15, 'symbol' => 'square', 'radius' => 3], 'simple' => self::SERIES_HIDDEN],
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
                    ['key' => 'etatPompeAqua', 'label' => 'Pompe aquarium', 'icon' => 'fa-water', 'color' => $pompeAqua, 'stats' => false, 'format' => 'onoff', 'css_class' => 'pump'],
                    ['key' => 'etatPompeTank', 'label' => 'Pompe réserve', 'icon' => 'fa-droplet', 'color' => $pompeTank, 'stats' => false, 'format' => 'onoff', 'css_class' => 'pump'],
                    ['key' => 'etatHeat', 'label' => 'Chauffage', 'icon' => 'fa-fire', 'color' => $chauffage, 'stats' => false, 'format' => 'onoff'],
                    ['key' => 'etatUV', 'label' => 'Lumière', 'icon' => 'fa-lightbulb', 'color' => $lumiere, 'stats' => false, 'format' => 'onoff'],
                    ['key' => 'bouffePetits', 'label' => 'Nourriture petits', 'icon' => 'fa-fish', 'color' => $nourPetits, 'stats' => false, 'format' => 'onoff', 'simple' => false],
                    ['key' => 'bouffeGros', 'label' => 'Nourriture gros', 'icon' => 'fa-fish', 'color' => $nourGros, 'stats' => false, 'format' => 'onoff', 'simple' => false],
                ],
                'chart' => [
                    'id' => 'chart-ffp3-actuators',
                    'kind' => 'cycles',
                    // Chronogramme : une ligne par actionneur, un marqueur par mesure « ON »
                    // (les lignes 0/1 superposées étaient illisibles).
                    'yAxis' => [[
                        'title' => ['text' => ''],
                        'categories' => $actuatorLanes,
                        'min' => 0,
                        'max' => count($actuatorLanes) - 1,
                        'tickInterval' => 1,
                        'reversed' => true,
                        'opposite' => false,
                    ]],
                    'series' => [
                        ['column' => 'etatPompeAqua', 'name' => 'Pompe aquarium', 'color' => $pompeAqua, 'on_markers' => ['lane' => 0, 'symbol' => 'square', 'radius' => 3]],
                        ['column' => 'etatPompeTank', 'name' => 'Pompe réserve', 'color' => $pompeTank, 'on_markers' => ['lane' => 1, 'symbol' => 'square', 'radius' => 3]],
                        ['column' => 'etatHeat', 'name' => 'Chauffage', 'color' => $chauffage, 'on_markers' => ['lane' => 2, 'symbol' => 'square', 'radius' => 3]],
                        ['column' => 'etatUV', 'name' => 'Lumière', 'color' => $lumiere, 'on_markers' => ['lane' => 3, 'symbol' => 'square', 'radius' => 3]],
                        ['column' => 'bouffePetits', 'name' => 'Nourriture petits', 'color' => $nourPetits, 'on_markers' => ['lane' => 4, 'symbol' => 'diamond', 'radius' => 5]],
                        ['column' => 'bouffeGros', 'name' => 'Nourriture gros', 'color' => $nourGros, 'on_markers' => ['lane' => 5, 'symbol' => 'diamond', 'radius' => 5]],
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
            [
                'id' => 'chimie',
                'type' => self::BLOCK_CUSTOM,
                'title' => 'Suivi des paramètres chimiques de l\'eau',
                'icon' => 'fa-flask',
                // Travail des élèves : visible dans tous les presets, même sans mesure capteur.
                'simple' => true,
                'requires_data' => false,
                'options' => [
                    'template' => 'partials/blocks/custom/_ffp3_chemistry.twig',
                    'sheets' => [
                        ['title' => 'Paramètres principaux', 'icon' => 'fa-chart-line', 'src' => $sheetBase . '209032696&format=interactive', 'sheet_title' => 'Paramètres principaux — analyses chimiques manuelles'],
                        ['title' => 'Nutriments et minéraux', 'icon' => 'fa-chart-area', 'src' => $sheetBase . '699943208&format=interactive', 'sheet_title' => 'Nutriments et minéraux — analyses chimiques manuelles'],
                        ['title' => 'Équilibre chimique', 'icon' => 'fa-chart-bar', 'src' => $sheetBase . '1338338691&format=interactive', 'sheet_title' => 'Équilibre chimique — analyses chimiques manuelles'],
                    ],
                ],
            ],
        ];
    }
}
