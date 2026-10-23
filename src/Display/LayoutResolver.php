<?php

declare(strict_types=1);

namespace App\Display;

/**
 * Applique un preset d'affichage (ordre + visibilité) au catalogue d'une page.
 *
 * Format d'un layout de preset (JSON stocké en BDD) :
 *   { "blocks": [ { "id": "sol", "visible": true, "chart": true,
 *                   "cards":  { "HumidMoy": "shown", "Humid1": "hidden" },
 *                   "series": { "HumidMoy": "shown", "Humid1": "legend" } } ] }
 *
 * L'ordre des clés de `blocks`, `cards` et `series` est l'ordre d'affichage.
 * Règles de fusion (tolérantes à l'évolution du catalogue) :
 *  - un identifiant inconnu du catalogue est ignoré ;
 *  - un élément du catalogue absent du preset prend sa visibilité par défaut et
 *    s'insère juste après son prédécesseur dans l'ordre du catalogue.
 */
final class LayoutResolver
{
    private const SLUG_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Valide et normalise un layout brut. En mode strict (API d'écriture), toute
     * anomalie lève une exception ; sinon les entrées invalides sont ignorées.
     *
     * @return array{blocks: list<array<string, mixed>>}
     *
     * @throws \InvalidArgumentException
     */
    public function normalize(PageCatalog $catalog, mixed $raw, bool $strict = false): array
    {
        if ($raw === null || $raw === []) {
            return ['blocks' => []];
        }
        if (!is_array($raw) || !isset($raw['blocks']) || !is_array($raw['blocks']) || !array_is_list($raw['blocks'])) {
            return $this->fail($strict, 'Le layout doit contenir une liste « blocks ».');
        }

        $blocks = [];
        $seen = [];
        foreach ($raw['blocks'] as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (!is_string($id) || !preg_match(self::SLUG_PATTERN, $id)) {
                $this->fail($strict, 'Bloc sans identifiant valide.');
                continue;
            }
            $def = $catalog->block($id);
            if ($def === null) {
                $this->fail($strict, "Bloc inconnu : {$id}.");
                continue;
            }
            if (isset($seen[$id])) {
                $this->fail($strict, "Bloc en double : {$id}.");
                continue;
            }
            $seen[$id] = true;

            $block = ['id' => $id];
            foreach (['visible', 'chart'] as $flag) {
                if (array_key_exists($flag, $entry)) {
                    if (!is_bool($entry[$flag])) {
                        $this->fail($strict, "« {$flag} » doit être un booléen ({$id}).");
                        continue;
                    }
                    $block[$flag] = $entry[$flag];
                }
            }

            $cardKeys = array_column($def['cards'], 'key');
            $block['cards'] = $this->normalizeStates(
                $entry['cards'] ?? [],
                $cardKeys,
                [PageCatalog::CARD_SHOWN, PageCatalog::CARD_HIDDEN],
                $strict,
                "cartes de {$id}"
            );

            $seriesIds = array_column($def['chart']['series'] ?? [], 'id');
            $block['series'] = $this->normalizeStates(
                $entry['series'] ?? [],
                $seriesIds,
                PageCatalog::SERIES_STATES,
                $strict,
                "séries de {$id}"
            );

            $blocks[] = $block;
        }

        return ['blocks' => $blocks];
    }

    /**
     * État complet et ordonné de TOUS les éléments du catalogue pour un layout
     * (base de l'éditeur et du rendu).
     *
     * @param array{blocks?: list<array<string, mixed>>} $layout layout normalisé
     * @return list<array{id: string, visible: bool, chart: bool, cards: array<string, string>, series: array<string, string>}>
     */
    public function expand(PageCatalog $catalog, array $layout): array
    {
        $byId = [];
        foreach ($layout['blocks'] ?? [] as $entry) {
            $byId[$entry['id']] = $entry;
        }

        $catalogIds = array_column($catalog->blocks(), 'id');
        $expanded = [];
        foreach ($this->mergeOrder($catalogIds, array_keys($byId)) as $id) {
            $def = $catalog->block($id);
            if ($def === null) {
                continue;
            }
            $entry = $byId[$id] ?? [];

            $cardDefaults = [];
            foreach ($def['cards'] as $card) {
                $cardDefaults[$card['key']] = $card['default'] ? PageCatalog::CARD_SHOWN : PageCatalog::CARD_HIDDEN;
            }
            $seriesDefaults = [];
            foreach ($def['chart']['series'] ?? [] as $s) {
                $seriesDefaults[$s['id']] = $s['default'];
            }

            $expanded[] = [
                'id' => $id,
                'visible' => (bool) ($entry['visible'] ?? $def['default']),
                'chart' => (bool) ($entry['chart'] ?? true),
                'cards' => $this->mergeStates($cardDefaults, $entry['cards'] ?? []),
                'series' => $this->mergeStates($seriesDefaults, $entry['series'] ?? []),
            ];
        }

        return $expanded;
    }

    /**
     * Layout prêt pour le rendu : blocs visibles (cartes et séries filtrées,
     * dans l'ordre du preset), graphiques à créer et carte capteur → série
     * générée (index réel des séries, utilisée par le live).
     *
     * @param array{blocks?: list<array<string, mixed>>} $layout layout normalisé
     * @return array{blocks: list<array<string, mixed>>, charts: list<array<string, mixed>>, sensor_map: array<string, array{chartId: string, seriesIndex: int}>}
     */
    public function resolve(PageCatalog $catalog, array $layout): array
    {
        $blocks = [];
        $charts = [];
        $sensorMap = [];

        foreach ($this->expand($catalog, $layout) as $state) {
            if (!$state['visible']) {
                continue;
            }
            $def = $catalog->block($state['id']);
            if ($def === null) {
                continue;
            }

            $cardsByKey = array_column($def['cards'], null, 'key');
            $cards = [];
            foreach ($state['cards'] as $key => $cardState) {
                if ($cardState === PageCatalog::CARD_SHOWN && isset($cardsByKey[$key])) {
                    $cards[] = $cardsByKey[$key];
                }
            }

            $chart = null;
            if ($def['chart'] !== null && $state['chart']) {
                $seriesById = array_column($def['chart']['series'], null, 'id');
                $series = [];
                foreach ($state['series'] as $id => $seriesState) {
                    if ($seriesState === PageCatalog::SERIES_HIDDEN || !isset($seriesById[$id])) {
                        continue;
                    }
                    $series[] = $seriesById[$id] + ['visible' => $seriesState === PageCatalog::SERIES_SHOWN];
                }
                if ($series !== []) {
                    $chart = $def['chart'];
                    $chart['series'] = $series;
                    $charts[] = $chart;
                    foreach ($series as $index => $s) {
                        if ($s['live'] && !isset($sensorMap[$s['column']])) {
                            $sensorMap[$s['column']] = ['chartId' => $chart['id'], 'seriesIndex' => $index];
                        }
                    }
                }
            }

            $def['cards'] = $cards;
            $def['chart'] = $chart;
            $blocks[] = $def;
        }

        return ['blocks' => $blocks, 'charts' => $charts, 'sensor_map' => $sensorMap];
    }

    /**
     * Ordre final : celui du preset (identifiants connus), puis chaque élément
     * manquant inséré après son prédécesseur le plus proche dans le catalogue.
     *
     * @param list<string> $catalogOrder
     * @param list<string> $presetOrder
     * @return list<string>
     */
    public function mergeOrder(array $catalogOrder, array $presetOrder): array
    {
        $order = array_values(array_unique(array_intersect($presetOrder, $catalogOrder)));

        foreach ($catalogOrder as $position => $id) {
            if (in_array($id, $order, true)) {
                continue;
            }
            $insertAt = 0;
            for ($p = $position - 1; $p >= 0; $p--) {
                $found = array_search($catalogOrder[$p], $order, true);
                if ($found !== false) {
                    $insertAt = $found + 1;
                    break;
                }
            }
            array_splice($order, $insertAt, 0, [$id]);
        }

        return $order;
    }

    /**
     * @param array<string, string> $defaults état par défaut, dans l'ordre du catalogue
     * @param array<string, string> $states   état du preset, dans l'ordre du preset
     * @return array<string, string>
     */
    private function mergeStates(array $defaults, array $states): array
    {
        $merged = [];
        foreach ($this->mergeOrder(array_keys($defaults), array_map('strval', array_keys($states))) as $key) {
            $merged[$key] = $states[$key] ?? $defaults[$key];
        }

        return $merged;
    }

    /**
     * @param list<string> $knownKeys
     * @param list<string> $allowed
     * @return array<string, string>
     */
    private function normalizeStates(mixed $raw, array $knownKeys, array $allowed, bool $strict, string $context): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }
        if (!is_array($raw) || array_is_list($raw)) {
            $this->fail($strict, "Format invalide ({$context}) : objet { clé: état } attendu.");

            return [];
        }

        $states = [];
        foreach ($raw as $key => $state) {
            $key = (string) $key;
            if (!in_array($key, $knownKeys, true)) {
                $this->fail($strict, "Élément inconnu ({$context}) : {$key}.");
                continue;
            }
            if (!is_string($state) || !in_array($state, $allowed, true)) {
                $this->fail($strict, "État invalide ({$context}) pour {$key}.");
                continue;
            }
            $states[$key] = $state;
        }

        return $states;
    }

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    private function fail(bool $strict, string $message): array
    {
        if ($strict) {
            throw new \InvalidArgumentException($message);
        }

        return ['blocks' => []];
    }
}
