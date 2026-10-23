<?php

declare(strict_types=1);

namespace Tests\Display;

use App\Display\LayoutResolver;
use App\Display\PageCatalog;
use App\Display\PageCatalogRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Cohérence interne de chaque catalogue de page : identifiants uniques, séries
 * adossées à des colonnes extraites, types de blocs connus, presets intégrés valides.
 */
final class PageCatalogTest extends TestCase
{
    /**
     * @return iterable<string, array{0: PageCatalog}>
     */
    public static function catalogs(): iterable
    {
        foreach ((new PageCatalogRegistry())->all() as $key => $catalog) {
            yield $key => [$catalog];
        }
    }

    /**
     * @dataProvider catalogs
     */
    public function testIdentifiersAreUniqueAndSeriesUseExtractedColumns(PageCatalog $catalog): void
    {
        $blockIds = array_column($catalog->blocks(), 'id');
        $this->assertSame($blockIds, array_values(array_unique($blockIds)), 'identifiants de blocs uniques');

        $chartIds = [];
        foreach ($catalog->blocks() as $block) {
            $this->assertContains($block['type'], PageCatalog::BLOCK_TYPES, $block['id']);

            $cardKeys = array_column($block['cards'], 'key');
            $this->assertSame($cardKeys, array_values(array_unique($cardKeys)), "cartes uniques ({$block['id']})");
            foreach ($block['cards'] as $card) {
                if ($card['stats']) {
                    $this->assertContains($card['key'], $catalog->statsColumns(), "stats de {$card['key']}");
                }
            }

            if ($block['chart'] === null) {
                continue;
            }
            $chartIds[] = $block['chart']['id'];
            $seriesIds = array_column($block['chart']['series'], 'id');
            $this->assertSame($seriesIds, array_values(array_unique($seriesIds)), "séries uniques ({$block['id']})");
            $axisCount = count($block['chart']['yAxis']);
            foreach ($block['chart']['series'] as $s) {
                $source = $s['column'] ?? $s['trend_of'];
                $this->assertContains($source, $catalog->chartColumns(), "colonne de la série {$s['id']}");
                $this->assertContains($s['default'], PageCatalog::SERIES_STATES);
                $this->assertContains($s['simple'], PageCatalog::SERIES_STATES);
                $this->assertLessThan($axisCount, $s['yAxis'], "axe Y de {$s['id']}");
            }
        }
        $this->assertSame($chartIds, array_values(array_unique($chartIds)), 'identifiants de graphiques uniques');
    }

    /**
     * @dataProvider catalogs
     */
    public function testBuiltInPresetsAreValidLayouts(PageCatalog $catalog): void
    {
        $presets = $catalog->builtInPresets();
        $this->assertArrayHasKey(PageCatalog::PRESET_SIMPLE, $presets);
        $this->assertArrayHasKey(PageCatalog::PRESET_EXTENDED, $presets);

        $resolver = new LayoutResolver();
        foreach ($presets as $preset) {
            $layout = $resolver->normalize($catalog, $preset['layout'], true);
            $this->assertNotEmpty($resolver->resolve($catalog, $layout)['blocks'], $preset['slug']);
        }
    }

    /**
     * @dataProvider catalogs
     */
    public function testPageConfigProvidesFormAndMonitoringUrl(PageCatalog $catalog): void
    {
        $config = $catalog->pageConfig('prod');
        foreach (['hero_title', 'form_action', 'form_id', 'test_env', 'table_label'] as $key) {
            $this->assertArrayHasKey($key, $config);
        }
        $this->assertStringStartsWith('/', $catalog->monitoringUrl('prod'));
        $this->assertStringStartsWith('/', $catalog->monitoringUrl($config['test_env']));
    }
}
