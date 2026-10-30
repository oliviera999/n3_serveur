<?php

declare(strict_types=1);

namespace App\Display;

/**
 * Catalogue déclaratif d'une page de suivi : décrit TOUT ce que la page sait
 * afficher (cadres, cartes, séries de graphiques), avec des identifiants stables.
 *
 * Les presets d'affichage (cf. LayoutResolver) ne sont qu'une surcouche
 * (ordre + visibilité) appliquée à ce catalogue : ils ne dupliquent jamais les
 * définitions. Un élément ajouté ici apparaît donc automatiquement dans les
 * presets existants selon sa visibilité par défaut (`default`).
 *
 * Format d'un bloc (cadre) retourné par defineBlocks() :
 *   - id (string, stable), type (BLOCK_*), title, icon ;
 *   - default (bool, visible par défaut), simple (bool, visible dans le preset « simple ») ;
 *   - requires_data (bool) : masqué si la page n'a aucune donnée ;
 *   - cards : liste de cartes { key, label, icon, color, unit, decimals, stats, hint,
 *     css_class, if_present, default, simple } ;
 *   - chart : { id, kind (main|cycles), yAxis, tooltip, series: [ { id, column, name,
 *     type, color, fill [r,g,b,top?,bottom?], lineWidth, dashStyle, yAxis, tooltip,
 *     opacity, trend_of, trend_round, default (shown|legend), simple } ] } ;
 *   - options : paramètres propres au type (template, src, form_id…).
 */
abstract class PageCatalog
{
    public const BLOCK_CHART_SECTION = 'chart_section';
    public const BLOCK_CAPTION = 'caption';
    public const BLOCK_FILTER_HEALTH = 'filter_health';
    public const BLOCK_GOOGLE_SHEET = 'google_sheet';
    public const BLOCK_CUSTOM = 'custom';
    public const BLOCK_TIDE_PANEL = 'tide_panel';
    public const BLOCK_WATER_BALANCE = 'water_balance';

    public const BLOCK_TYPES = [
        self::BLOCK_CHART_SECTION,
        self::BLOCK_CAPTION,
        self::BLOCK_FILTER_HEALTH,
        self::BLOCK_GOOGLE_SHEET,
        self::BLOCK_CUSTOM,
        self::BLOCK_TIDE_PANEL,
        self::BLOCK_WATER_BALANCE,
    ];

    public const SERIES_SHOWN = 'shown';
    public const SERIES_LEGEND = 'legend';
    public const SERIES_HIDDEN = 'hidden';

    public const SERIES_STATES = [self::SERIES_SHOWN, self::SERIES_LEGEND, self::SERIES_HIDDEN];

    public const CARD_SHOWN = 'shown';
    public const CARD_HIDDEN = 'hidden';

    public const PRESET_SIMPLE = 'simple';
    public const PRESET_EXTENDED = 'etendu';
    public const PRESET_ELEVES = 'eleves';
    public const PRESET_VISITE = 'visite';
    public const PRESET_TECHNIQUE = 'technique';

    /** @var list<array<string, mixed>>|null */
    private ?array $normalizedBlocks = null;

    /** Clé de page (msp1, n3pp, energie) : identifiant des presets en BDD. */
    abstract public function pageKey(): string;

    /** Libellé lisible de la page (éditeur de presets). */
    abstract public function label(): string;

    /**
     * Colonnes extraites pour les graphiques. La 1re sert de référence au
     * sous-échantillonnage (ChartDataService::prepareGenericSeries).
     *
     * @return list<string>
     */
    abstract public function chartColumns(): array;

    /** @return list<string> Colonnes dont on calcule avg/min/max. */
    abstract public function statsColumns(): array;

    /**
     * Configuration de page (hero, formulaire, libellés) selon l'environnement.
     *
     * @return array<string, mixed>
     */
    abstract public function pageConfig(string $environment): array;

    /** URL (sans base_path) de la page de suivi pour l'environnement donné. */
    abstract public function monitoringUrl(string $environment): string;

    /** @return list<array<string, mixed>> */
    abstract protected function defineBlocks(): array;

    /**
     * Blocs normalisés (valeurs par défaut appliquées), dans l'ordre du catalogue.
     *
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        if ($this->normalizedBlocks === null) {
            $this->normalizedBlocks = array_map(
                fn (array $block): array => $this->normalizeBlock($block),
                $this->defineBlocks()
            );
        }

        return $this->normalizedBlocks;
    }

    /** @return array<string, mixed>|null */
    public function block(string $id): ?array
    {
        foreach ($this->blocks() as $block) {
            if ($block['id'] === $id) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Presets intégrés (repli quand la BDD est vide ou indisponible) :
     *  - « étendu » : tout le catalogue avec ses visibilités par défaut (affichage historique) ;
     *  - « simple » : dérivé des drapeaux `simple` des blocs, cartes et séries ;
     *  - publics pédagogiques : « visite », « élèves », « technique » (mêmes bases, libellés audience).
     *
     * @return array<string, array<string, mixed>>
     */
    public function builtInPresets(): array
    {
        $simpleLayout = ['blocks' => $this->simpleLayoutBlocks()];
        $extendedLayout = ['blocks' => []];

        return [
            self::PRESET_SIMPLE => [
                'slug' => self::PRESET_SIMPLE,
                'label' => 'Simple',
                'description' => 'L\'essentiel : les mesures principales, sans le détail technique.',
                'layout' => $simpleLayout,
                'is_default' => false,
                'show_on_page' => true,
                'sort_order' => 10,
            ],
            self::PRESET_VISITE => [
                'slug' => self::PRESET_VISITE,
                'label' => 'Visite',
                'description' => 'Vue courte pour parents et visiteurs : l\'essentiel en un coup d\'œil.',
                'layout' => $simpleLayout,
                'is_default' => false,
                'show_on_page' => true,
                'sort_order' => 12,
            ],
            self::PRESET_ELEVES => [
                'slug' => self::PRESET_ELEVES,
                'label' => 'Élèves',
                'description' => 'Vue pédagogique collège / lycée : mesures principales, jargon minimal.',
                'layout' => $simpleLayout,
                'is_default' => false,
                'show_on_page' => true,
                'sort_order' => 14,
            ],
            self::PRESET_EXTENDED => [
                'slug' => self::PRESET_EXTENDED,
                'label' => 'Étendu',
                'description' => 'Toutes les mesures, tous les graphiques et le suivi système.',
                'layout' => $extendedLayout,
                'is_default' => true,
                'show_on_page' => true,
                'sort_order' => 20,
            ],
            self::PRESET_TECHNIQUE => [
                'slug' => self::PRESET_TECHNIQUE,
                'label' => 'Technique',
                'description' => 'Vue complète pour opérateurs et maintenance (équivalent étendu).',
                'layout' => $extendedLayout,
                'is_default' => false,
                'show_on_page' => true,
                'sort_order' => 30,
            ],
        ];
    }

    /**
     * Blocs du preset « simple » (drapeaux `simple` du catalogue).
     *
     * @return list<array<string, mixed>>
     */
    protected function simpleLayoutBlocks(): array
    {
        $simpleBlocks = [];
        foreach ($this->blocks() as $block) {
            $cards = [];
            foreach ($block['cards'] as $card) {
                $cards[$card['key']] = $card['simple'] ? self::CARD_SHOWN : self::CARD_HIDDEN;
            }
            $series = [];
            foreach ($block['chart']['series'] ?? [] as $s) {
                $series[$s['id']] = $s['simple'];
            }
            $simpleBlocks[] = [
                'id' => $block['id'],
                'visible' => $block['simple'],
                'chart' => true,
                'cards' => $cards,
                'series' => $series,
            ];
        }

        return $simpleBlocks;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function normalizeBlock(array $block): array
    {
        $type = (string) ($block['type'] ?? self::BLOCK_CHART_SECTION);
        $default = (bool) ($block['default'] ?? true);
        $dataTypes = [
            self::BLOCK_CHART_SECTION,
            self::BLOCK_CAPTION,
            self::BLOCK_CUSTOM,
            self::BLOCK_TIDE_PANEL,
            self::BLOCK_WATER_BALANCE,
        ];

        $cards = [];
        foreach ($block['cards'] ?? [] as $card) {
            $cardDefault = (bool) ($card['default'] ?? true);
            $cards[] = [
                'key' => (string) $card['key'],
                'label' => (string) ($card['label'] ?? $card['key']),
                'icon' => (string) ($card['icon'] ?? 'fa-circle'),
                'color' => (string) ($card['color'] ?? '#7f8c8d'),
                'unit' => (string) ($card['unit'] ?? ''),
                'decimals' => (int) ($card['decimals'] ?? 0),
                'stats' => (bool) ($card['stats'] ?? true),
                'hint' => isset($card['hint']) ? (string) $card['hint'] : null,
                'css_class' => (string) ($card['css_class'] ?? ''),
                'if_present' => (bool) ($card['if_present'] ?? false),
                'default' => $cardDefault,
                'simple' => (bool) ($card['simple'] ?? $cardDefault),
            ];
        }

        $chart = null;
        if (isset($block['chart']) && is_array($block['chart'])) {
            $series = [];
            foreach ($block['chart']['series'] ?? [] as $s) {
                $seriesDefault = (string) ($s['default'] ?? self::SERIES_SHOWN);
                $column = isset($s['column']) ? (string) $s['column'] : null;
                $trendOf = isset($s['trend_of']) ? (string) $s['trend_of'] : null;
                $series[] = [
                    'id' => (string) ($s['id'] ?? $column ?? $trendOf),
                    'column' => $column,
                    'name' => (string) $s['name'],
                    'type' => (string) ($s['type'] ?? 'spline'),
                    'color' => (string) ($s['color'] ?? '#7f8c8d'),
                    'fill' => isset($s['fill']) ? array_values($s['fill']) : null,
                    'lineWidth' => $s['lineWidth'] ?? 2,
                    'dashStyle' => $s['dashStyle'] ?? null,
                    'yAxis' => (int) ($s['yAxis'] ?? 0),
                    'tooltip' => $s['tooltip'] ?? null,
                    'opacity' => $s['opacity'] ?? null,
                    'trend_of' => $trendOf,
                    'trend_round' => (bool) ($s['trend_round'] ?? false),
                    'live' => $column !== null && $trendOf === null,
                    'default' => $seriesDefault,
                    'simple' => (string) ($s['simple'] ?? $seriesDefault),
                ];
            }
            $chart = [
                'id' => (string) $block['chart']['id'],
                'kind' => (string) ($block['chart']['kind'] ?? 'main'),
                'yAxis' => $block['chart']['yAxis'] ?? [['title' => ['text' => ''], 'opposite' => false]],
                'tooltip' => $block['chart']['tooltip'] ?? null,
                'series' => $series,
            ];
        }

        return [
            'id' => (string) $block['id'],
            'type' => $type,
            'title' => (string) ($block['title'] ?? ''),
            'icon' => (string) ($block['icon'] ?? 'fa-square'),
            'default' => $default,
            'simple' => (bool) ($block['simple'] ?? $default),
            'requires_data' => (bool) ($block['requires_data'] ?? in_array($type, $dataTypes, true)),
            'cards' => $cards,
            'chart' => $chart,
            'options' => $block['options'] ?? [],
        ];
    }
}
