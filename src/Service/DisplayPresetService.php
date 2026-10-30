<?php

declare(strict_types=1);

namespace App\Service;

use App\Display\LayoutResolver;
use App\Display\PageCatalog;
use App\Display\PageCatalogRegistry;
use App\Domain\User;
use App\Repository\DisplayPresetRepository;

/**
 * Presets d'affichage des pages de suivi : fusion des presets intégrés (code)
 * et des presets enregistrés (BDD), sélection du preset d'une requête,
 * validation et écriture depuis l'éditeur des pages de contrôle.
 *
 * Lecture « offline-first » : si la table est inaccessible, seuls les presets
 * intégrés sont proposés et la page de suivi s'affiche normalement.
 */
class DisplayPresetService
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';
    private const LABEL_MAX = 64;
    private const DESCRIPTION_MAX = 255;

    public function __construct(
        private DisplayPresetRepository $repository,
        private PageCatalogRegistry $registry,
        private LayoutResolver $resolver,
        private LogService $logger,
    ) {
    }

    public function catalog(string $pageKey): ?PageCatalog
    {
        return $this->registry->get($pageKey);
    }

    /**
     * Presets de la page (intégrés + enregistrés), triés. Un preset enregistré
     * dont le slug est celui d'un preset intégré le remplace (« personnalisé »).
     * Un seul preset porte `is_default` (enregistré prioritaire, sinon « étendu »).
     *
     * @return list<array<string, mixed>>
     */
    public function presets(PageCatalog $catalog): array
    {
        $builtIn = $catalog->builtInPresets();
        $presets = [];
        foreach ($builtIn as $slug => $preset) {
            $presets[$slug] = $preset + ['builtin' => true, 'customized' => false, 'updated_at' => null, 'updated_by' => null];
        }

        $hasStoredDefault = false;
        foreach ($this->storedRows($catalog->pageKey()) as $row) {
            $decoded = json_decode($row['layout_json'], true);
            $presets[$row['slug']] = [
                'slug' => $row['slug'],
                'label' => $row['label'],
                'description' => $row['description'],
                'layout' => $this->resolver->normalize($catalog, $decoded),
                'is_default' => $row['is_default'],
                'show_on_page' => $row['show_on_page'],
                'sort_order' => $row['sort_order'],
                'builtin' => isset($builtIn[$row['slug']]),
                'customized' => isset($builtIn[$row['slug']]),
                'updated_at' => $row['updated_at'],
                'updated_by' => $row['updated_by'],
            ];
            $hasStoredDefault = $hasStoredDefault || $row['is_default'];
        }

        if ($hasStoredDefault) {
            foreach ($builtIn as $slug => $_) {
                if (!$presets[$slug]['customized']) {
                    $presets[$slug]['is_default'] = false;
                }
            }
        }

        $list = array_values($presets);
        usort($list, static fn (array $a, array $b): int => [$a['sort_order'], $a['label']] <=> [$b['sort_order'], $b['label']]);

        return $list;
    }

    /**
     * Preset à appliquer : celui demandé (`?preset=`), même s'il n'est pas
     * proposé aux visiteurs (prévisualisation), sinon le preset par défaut.
     * Sans `?preset=`, un rôle « reader » (ou anonyme) préfère « simple » tant
     * que le défaut n'a pas été personnalisé en base (reste le builtin étendu).
     *
     * @param list<array<string, mixed>> $presets
     * @return array<string, mixed>
     */
    public function select(array $presets, ?string $requestedSlug, ?string $viewerRole = null): array
    {
        $default = $presets[0];
        $hasCustomizedDefault = false;
        foreach ($presets as $preset) {
            if ($requestedSlug !== null && $preset['slug'] === $requestedSlug) {
                return $preset;
            }
            if ($preset['is_default']) {
                $default = $preset;
                $hasCustomizedDefault = (bool) ($preset['customized'] ?? false)
                    || !(bool) ($preset['builtin'] ?? false);
            }
        }

        if (
            $requestedSlug === null
            && !$hasCustomizedDefault
            && $default['slug'] === PageCatalog::PRESET_EXTENDED
            && $this->prefersSimplePreset($viewerRole)
        ) {
            foreach ($presets as $preset) {
                if ($preset['slug'] === PageCatalog::PRESET_SIMPLE) {
                    return $preset;
                }
            }
        }

        return $default;
    }

    /**
     * Lecteurs / anonymes : vue simple par défaut (sauf défaut opérateur en BDD).
     */
    private function prefersSimplePreset(?string $viewerRole): bool
    {
        if ($viewerRole === null || $viewerRole === '') {
            return true;
        }

        return $viewerRole === User::ROLE_READER;
    }

    /**
     * Contexte Twig/JS d'une page de suivi pour le preset retenu.
     *
     * @return array{layout: array<string, mixed>, preset: array<string, mixed>, default_preset_slug: string, presets_menu: list<array<string, mixed>>, chart_ids_json: string, sensor_map_json: string}
     */
    public function pageContext(PageCatalog $catalog, ?string $requestedSlug, ?string $viewerRole = null): array
    {
        $presets = $this->presets($catalog);
        $preset = $this->select($presets, $requestedSlug, $viewerRole);
        $default = $this->select($presets, null, $viewerRole);
        $layout = $this->resolver->resolve($catalog, $preset['layout']);

        $menu = [];
        foreach ($presets as $p) {
            if ($p['show_on_page'] || $p['slug'] === $preset['slug']) {
                $menu[] = [
                    'slug' => $p['slug'],
                    'label' => $p['label'],
                    'description' => $p['description'],
                    'is_default' => $p['is_default'],
                    'active' => $p['slug'] === $preset['slug'],
                ];
            }
        }

        return [
            'layout' => $layout,
            'preset' => ['slug' => $preset['slug'], 'label' => $preset['label']],
            'default_preset_slug' => $default['slug'],
            'presets_menu' => $menu,
            'chart_ids_json' => (string) json_encode(array_column($layout['charts'], 'id')),
            'sensor_map_json' => (string) json_encode((object) $layout['sensor_map']),
        ];
    }

    /**
     * Données de l'éditeur : arbre du catalogue + presets avec état complet.
     *
     * @return array<string, mixed>
     */
    public function editorData(PageCatalog $catalog, string $environment): array
    {
        $blocks = [];
        foreach ($catalog->blocks() as $block) {
            $blocks[] = [
                'id' => $block['id'],
                'type' => $block['type'],
                'title' => $block['title'],
                'icon' => $block['icon'],
                'has_chart' => $block['chart'] !== null,
                'cards' => array_map(static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label']], $block['cards']),
                'series' => array_map(static fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'color' => $s['color']], $block['chart']['series'] ?? []),
            ];
        }

        $presets = [];
        foreach ($this->presets($catalog) as $preset) {
            $presets[] = [
                'slug' => $preset['slug'],
                'label' => $preset['label'],
                'description' => $preset['description'],
                'is_default' => $preset['is_default'],
                'show_on_page' => $preset['show_on_page'],
                'sort_order' => $preset['sort_order'],
                'builtin' => $preset['builtin'],
                'customized' => $preset['customized'],
                'updated_at' => $preset['updated_at'],
                'updated_by' => $preset['updated_by'],
                'blocks' => $this->resolver->expand($catalog, $preset['layout']),
            ];
        }

        return [
            'page' => $catalog->pageKey(),
            'label' => $catalog->label(),
            'monitoring_url' => $catalog->monitoringUrl($environment),
            'catalog' => $blocks,
            'presets' => $presets,
        ];
    }

    /**
     * Valide et enregistre un preset envoyé par l'éditeur.
     *
     * @param array<string, mixed> $input
     * @throws \InvalidArgumentException données invalides (message affichable)
     */
    public function save(PageCatalog $catalog, array $input, ?string $user): void
    {
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            throw new \InvalidArgumentException('Identifiant invalide : lettres minuscules, chiffres et tirets (40 max).');
        }
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > self::LABEL_MAX) {
            throw new \InvalidArgumentException('Le nom du preset est obligatoire (64 caractères max).');
        }
        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new \InvalidArgumentException('Description trop longue (255 caractères max).');
        }
        $layout = $this->resolver->normalize($catalog, $input['layout'] ?? null, true);

        $defaultOrder = $catalog->builtInPresets()[$slug]['sort_order'] ?? 100;
        $sortOrder = filter_var($input['sort_order'] ?? $defaultOrder, FILTER_VALIDATE_INT);
        $this->repository->save(
            $catalog->pageKey(),
            $slug,
            $label,
            $description,
            (string) json_encode($layout, JSON_UNESCAPED_UNICODE),
            (bool) ($input['is_default'] ?? false),
            (bool) ($input['show_on_page'] ?? true),
            $sortOrder === false ? 100 : max(0, min(9999, $sortOrder)),
            $user
        );
    }

    /**
     * Supprime un preset enregistré. Pour un preset intégré, revient à sa
     * définition du code (« réinitialiser »).
     */
    public function delete(PageCatalog $catalog, string $slug): bool
    {
        return $this->repository->delete($catalog->pageKey(), $slug);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedRows(string $pageKey): array
    {
        try {
            return $this->repository->findByPage($pageKey);
        } catch (\Throwable $e) {
            $this->logger->warning('Presets d\'affichage indisponibles, repli sur les presets intégrés', [
                'page' => $pageKey,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
