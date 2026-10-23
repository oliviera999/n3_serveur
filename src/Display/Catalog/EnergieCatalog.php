<?php

declare(strict_types=1);

namespace App\Display\Catalog;

use App\Display\PageCatalog;

/**
 * Catalogue d'affichage de la page de suivi du banc énergie (INA226).
 */
final class EnergieCatalog extends PageCatalog
{
    private const PANNEAU = '#f39c12';
    private const BATTERIE = '#27ae60';
    private const CONSO = '#e74c3c';

    public function pageKey(): string
    {
        return 'energie';
    }

    public function label(): string
    {
        return 'Banc énergie (INA226)';
    }

    /**
     * Ordre = ordre des séries des graphiques. PanneauP en tête : colonne de
     * référence du sous-échantillonnage (la plus variable).
     */
    public function chartColumns(): array
    {
        return [
            'PanneauP', 'BatterieP', 'ConsoP',
            'PanneauV', 'BatterieV', 'BatterieVmin', 'ConsoV', 'BatterieVadc',
            'PanneauI', 'PanneauImax', 'BatterieI', 'BatterieImin', 'BatterieImax', 'ConsoI', 'ConsoImax',
            'BatterieSoc', 'BatterieAh', 'EnergiePanneauWh', 'EnergieConsoWh',
        ];
    }

    public function statsColumns(): array
    {
        return [
            'PanneauV', 'PanneauI', 'PanneauP',
            'BatterieV', 'BatterieI', 'BatterieP', 'BatterieSoc', 'BatterieAh',
            'ConsoV', 'ConsoI', 'ConsoP',
            'EnergiePanneauWh', 'EnergieConsoWh',
            'Rssi', 'I2cErreurs',
        ];
    }

    public function pageConfig(string $environment): array
    {
        $isTest = $environment === 'energie_test';

        return [
            'hero_title' => 'Banc énergie — INA226',
            'hero_icon' => 'fa-solar-panel',
            'hero_subtitle' => 'Production solaire, charge et décharge de la batterie 12 V, consommation des périphériques : '
                . 'trois capteurs INA226 mesurés chaque seconde, agrégés toutes les 10 s.',
            'form_action' => $isTest ? '/energie-test' : '/energie',
            'form_id' => 'energieFilterForm',
            'test_env' => 'energie_test',
            'table_label' => $isTest ? 'energieDataTest' : 'energieData',
            'footer_text' => 'Banc énergie (INA226)',
            'uptime_label' => 'Uptime (24 h)',
        ];
    }

    public function monitoringUrl(string $environment): string
    {
        return $environment === 'energie_test' ? '/energie-test' : '/energie';
    }

    protected function defineBlocks(): array
    {
        // Ligne du zéro : le courant/la puissance batterie sont signés (> 0 charge, < 0 décharge).
        $zeroLine = [['value' => 0, 'width' => 1, 'color' => '#95a5a6', 'zIndex' => 3]];
        $signHint = '> 0 charge · < 0 décharge';

        return [
            [
                'id' => 'puissances',
                'title' => 'Puissances',
                'icon' => 'fa-bolt',
                'cards' => [
                    ['key' => 'PanneauP', 'label' => 'Puissance panneau', 'icon' => 'fa-solar-panel', 'color' => self::PANNEAU, 'unit' => 'W', 'decimals' => 2],
                    ['key' => 'BatterieP', 'label' => 'Puissance batterie', 'icon' => 'fa-car-battery', 'color' => self::BATTERIE, 'unit' => 'W', 'decimals' => 2, 'hint' => $signHint],
                    ['key' => 'ConsoP', 'label' => 'Puissance conso', 'icon' => 'fa-plug', 'color' => self::CONSO, 'unit' => 'W', 'decimals' => 2],
                ],
                'chart' => [
                    'id' => 'chart-puissances',
                    'yAxis' => [['title' => ['text' => 'Puissance (W)'], 'opposite' => false, 'plotLines' => $zeroLine]],
                    'tooltip' => ['valueDecimals' => 2, 'valueSuffix' => ' W'],
                    'series' => [
                        ['column' => 'PanneauP', 'name' => 'Panneau', 'type' => 'areaspline', 'color' => self::PANNEAU, 'fill' => [243, 156, 18, 0.22, 0.04]],
                        ['column' => 'BatterieP', 'name' => 'Batterie', 'color' => self::BATTERIE],
                        ['column' => 'ConsoP', 'name' => 'Conso', 'color' => self::CONSO],
                    ],
                ],
            ],
            [
                'id' => 'tensions',
                'title' => 'Tensions',
                'icon' => 'fa-wave-square',
                'simple' => false,
                'cards' => [
                    ['key' => 'PanneauV', 'label' => 'Tension panneau', 'icon' => 'fa-solar-panel', 'color' => self::PANNEAU, 'unit' => 'V', 'decimals' => 2],
                    ['key' => 'BatterieV', 'label' => 'Tension batterie', 'icon' => 'fa-car-battery', 'color' => self::BATTERIE, 'unit' => 'V', 'decimals' => 2],
                    ['key' => 'ConsoV', 'label' => 'Tension conso', 'icon' => 'fa-plug', 'color' => self::CONSO, 'unit' => 'V', 'decimals' => 2],
                ],
                'chart' => [
                    'id' => 'chart-tensions',
                    'yAxis' => [['title' => ['text' => 'Tension (V)'], 'opposite' => false]],
                    'tooltip' => ['valueDecimals' => 2, 'valueSuffix' => ' V'],
                    'series' => [
                        ['column' => 'PanneauV', 'name' => 'Panneau', 'color' => self::PANNEAU],
                        ['column' => 'BatterieV', 'name' => 'Batterie', 'color' => self::BATTERIE],
                        ['column' => 'BatterieVmin', 'name' => 'Batterie min.', 'color' => '#82e0aa', 'dashStyle' => 'Dash', 'lineWidth' => 1],
                        ['column' => 'ConsoV', 'name' => 'Conso', 'color' => self::CONSO],
                        ['column' => 'BatterieVadc', 'name' => 'Batterie (ADC)', 'color' => '#16a085', 'dashStyle' => 'Dot', 'lineWidth' => 1],
                    ],
                ],
            ],
            [
                'id' => 'courants',
                'title' => 'Courants',
                'icon' => 'fa-gauge-high',
                'simple' => false,
                'cards' => [
                    ['key' => 'PanneauI', 'label' => 'Courant panneau', 'icon' => 'fa-solar-panel', 'color' => self::PANNEAU, 'unit' => 'A', 'decimals' => 3],
                    ['key' => 'BatterieI', 'label' => 'Courant batterie', 'icon' => 'fa-car-battery', 'color' => self::BATTERIE, 'unit' => 'A', 'decimals' => 3, 'hint' => $signHint],
                    ['key' => 'ConsoI', 'label' => 'Courant conso', 'icon' => 'fa-plug', 'color' => self::CONSO, 'unit' => 'A', 'decimals' => 3],
                ],
                'chart' => [
                    'id' => 'chart-courants',
                    'yAxis' => [['title' => ['text' => 'Courant (A)'], 'opposite' => false, 'plotLines' => $zeroLine]],
                    'tooltip' => ['valueDecimals' => 3, 'valueSuffix' => ' A'],
                    'series' => [
                        ['column' => 'PanneauI', 'name' => 'Panneau', 'color' => self::PANNEAU],
                        ['column' => 'PanneauImax', 'name' => 'Panneau max.', 'color' => '#f8c471', 'dashStyle' => 'Dash', 'lineWidth' => 1],
                        ['column' => 'BatterieI', 'name' => 'Batterie', 'color' => self::BATTERIE],
                        ['column' => 'BatterieImin', 'name' => 'Batterie min.', 'color' => '#82e0aa', 'dashStyle' => 'Dash', 'lineWidth' => 1],
                        ['column' => 'BatterieImax', 'name' => 'Batterie max.', 'color' => '#1e8449', 'dashStyle' => 'Dash', 'lineWidth' => 1],
                        ['column' => 'ConsoI', 'name' => 'Conso', 'color' => self::CONSO],
                        ['column' => 'ConsoImax', 'name' => 'Conso max.', 'color' => '#f1948a', 'dashStyle' => 'Dash', 'lineWidth' => 1],
                    ],
                ],
            ],
            [
                'id' => 'batterie',
                'title' => 'Batterie & énergie',
                'icon' => 'fa-battery-half',
                'cards' => [
                    ['key' => 'BatterieSoc', 'label' => 'État de charge', 'icon' => 'fa-battery-half', 'color' => '#16a085', 'unit' => '%'],
                    ['key' => 'BatterieAh', 'label' => 'Compteur batterie (net)', 'icon' => 'fa-charging-station', 'color' => '#8e44ad', 'unit' => 'Ah', 'decimals' => 3],
                    ['key' => 'EnergiePanneauWh', 'label' => 'Énergie panneau (10 s)', 'icon' => 'fa-bolt', 'color' => '#f1c40f', 'unit' => 'Wh', 'decimals' => 3, 'simple' => false],
                    ['key' => 'EnergieConsoWh', 'label' => 'Énergie conso (10 s)', 'icon' => 'fa-bolt', 'color' => '#c0392b', 'unit' => 'Wh', 'decimals' => 3, 'simple' => false],
                ],
                'chart' => [
                    'id' => 'chart-batterie',
                    'yAxis' => [
                        ['title' => ['text' => 'État de charge (%)'], 'opposite' => false, 'min' => 0, 'max' => 100],
                        ['title' => ['text' => 'Compteur (Ah)'], 'opposite' => true],
                        ['title' => ['text' => 'Énergie / 10 s (Wh)'], 'opposite' => true, 'offset' => 60, 'min' => 0],
                    ],
                    'series' => [
                        ['column' => 'BatterieSoc', 'name' => 'État de charge', 'type' => 'areaspline', 'color' => '#16a085', 'fill' => [22, 160, 133, 0.18, 0.03], 'tooltip' => ['valueDecimals' => 0, 'valueSuffix' => ' %']],
                        ['column' => 'BatterieAh', 'name' => 'Compteur Ah', 'color' => '#8e44ad', 'yAxis' => 1, 'tooltip' => ['valueDecimals' => 3, 'valueSuffix' => ' Ah']],
                        ['column' => 'EnergiePanneauWh', 'name' => 'Énergie panneau', 'color' => '#f1c40f', 'lineWidth' => 1, 'yAxis' => 2, 'tooltip' => ['valueDecimals' => 3, 'valueSuffix' => ' Wh'], 'simple' => self::SERIES_HIDDEN],
                        ['column' => 'EnergieConsoWh', 'name' => 'Énergie conso', 'color' => '#c0392b', 'lineWidth' => 1, 'yAxis' => 2, 'tooltip' => ['valueDecimals' => 3, 'valueSuffix' => ' Wh'], 'simple' => self::SERIES_HIDDEN],
                    ],
                ],
            ],
            [
                'id' => 'systeme',
                'title' => 'Système',
                'icon' => 'fa-microchip',
                'simple' => false,
                'cards' => [
                    ['key' => 'Rssi', 'label' => 'Signal WiFi', 'icon' => 'fa-wifi', 'color' => '#2c3e50', 'unit' => 'dBm'],
                    ['key' => 'I2cErreurs', 'label' => 'Erreurs I²C', 'icon' => 'fa-microchip', 'color' => '#7f8c8d'],
                ],
            ],
            [
                'id' => 'capteur',
                'type' => self::BLOCK_CUSTOM,
                'title' => 'Capteur, INA226 et firmware',
                'icon' => 'fa-microchip',
                'simple' => false,
                'options' => ['template' => 'partials/blocks/custom/_energie_caption.twig'],
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
