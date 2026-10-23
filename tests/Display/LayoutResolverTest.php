<?php

declare(strict_types=1);

namespace Tests\Display;

use App\Display\Catalog\N3ppCatalog;
use App\Display\LayoutResolver;
use App\Display\PageCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Fusion catalogue + preset : ordre, visibilité, tolérance aux identifiants
 * inconnus, éléments ajoutés au catalogue après la création du preset, et
 * carte capteur → série générée d'après les séries réellement rendues.
 */
final class LayoutResolverTest extends TestCase
{
    private LayoutResolver $resolver;
    private PageCatalog $catalog;

    protected function setUp(): void
    {
        $this->resolver = new LayoutResolver();
        $this->catalog = new N3ppCatalog();
    }

    public function testEmptyLayoutReproducesCatalogDefaults(): void
    {
        $resolved = $this->resolver->resolve($this->catalog, ['blocks' => []]);

        $this->assertSame(['sol', 'physique', 'systeme', 'capteur', 'filtrage'], array_column($resolved['blocks'], 'id'));
        $this->assertSame(['chart-niveauxeaux', 'chart-temperatures', 'chart-cycles'], array_column($resolved['charts'], 'id'));

        $soil = $resolved['charts'][0]['series'];
        $this->assertSame('HumidMoy', $soil[0]['id']);
        $this->assertTrue($soil[0]['visible']);
        $this->assertSame('HumidMoyTendance', $soil[1]['id']);
        $this->assertFalse($soil[2]['visible'], 'Humid1 : présente dans la légende mais décochée par défaut');

        // Index réels des séries rendues (la tendance occupe l'index 1, sans mapping live).
        $this->assertSame(['chartId' => 'chart-niveauxeaux', 'seriesIndex' => 0], $resolved['sensor_map']['HumidMoy']);
        $this->assertSame(['chartId' => 'chart-niveauxeaux', 'seriesIndex' => 2], $resolved['sensor_map']['Humid1']);
        $this->assertSame(['chartId' => 'chart-niveauxeaux', 'seriesIndex' => 7], $resolved['sensor_map']['resetMode']);
        $this->assertArrayNotHasKey('HumidMoyTendance', $resolved['sensor_map']);
    }

    public function testPresetReordersAndHidesElements(): void
    {
        $layout = $this->resolver->normalize($this->catalog, ['blocks' => [
            ['id' => 'physique', 'cards' => ['Luminosite' => 'shown', 'TempAir' => 'shown', 'Humidite' => 'hidden'],
                'series' => ['Humidite' => 'hidden', 'Luminosite' => 'legend']],
            ['id' => 'sol', 'series' => ['Humid1' => 'hidden', 'Humid2' => 'hidden', 'Humid3' => 'hidden', 'Humid4' => 'hidden']],
            ['id' => 'systeme', 'visible' => false],
        ]], true);
        $resolved = $this->resolver->resolve($this->catalog, $layout);

        $this->assertSame(['physique', 'sol', 'capteur', 'filtrage'], array_column($resolved['blocks'], 'id'));
        $this->assertSame(['Luminosite', 'TempAir', 'Pression'], array_column($resolved['blocks'][0]['cards'], 'key'));

        // TempAir absent du preset : réinséré à sa place du catalogue (en tête).
        $physique = $resolved['blocks'][0]['chart'];
        $this->assertSame(['TempAir', 'Luminosite', 'Pression'], array_column($physique['series'], 'id'));
        $this->assertFalse($physique['series'][1]['visible']);
        $this->assertSame(0, $resolved['sensor_map']['TempAir']['seriesIndex']);
        $this->assertSame(2, $resolved['sensor_map']['Pression']['seriesIndex']);
        $this->assertArrayNotHasKey('Humidite', $resolved['sensor_map']);
        $this->assertArrayNotHasKey('bootCount', $resolved['sensor_map'], 'bloc système masqué');

        $sol = $resolved['blocks'][1]['chart'];
        $this->assertSame(['HumidMoy', 'HumidMoyTendance', 'etatPompe', 'resetMode'], array_column($sol['series'], 'id'));
        $this->assertSame(2, $resolved['sensor_map']['etatPompe']['seriesIndex']);
    }

    public function testChartCanBeHiddenWhileKeepingCards(): void
    {
        $resolved = $this->resolver->resolve($this->catalog, ['blocks' => [['id' => 'systeme', 'chart' => false]]]);
        $systeme = $resolved['blocks'][array_search('systeme', array_column($resolved['blocks'], 'id'), true)];

        $this->assertNull($systeme['chart']);
        $this->assertCount(2, $systeme['cards']);
        $this->assertNotContains('chart-cycles', array_column($resolved['charts'], 'id'));
    }

    public function testAllSeriesHiddenDropsTheChart(): void
    {
        $resolved = $this->resolver->resolve($this->catalog, ['blocks' => [
            ['id' => 'systeme', 'series' => ['bootCount' => 'hidden', 'PontDiv' => 'hidden']],
        ]]);

        $this->assertNotContains('chart-cycles', array_column($resolved['charts'], 'id'));
    }

    public function testTolerantNormalizationIgnoresUnknownIdentifiers(): void
    {
        $layout = $this->resolver->normalize($this->catalog, ['blocks' => [
            ['id' => 'disparu', 'visible' => true],
            ['id' => 'sol', 'cards' => ['Ancienne' => 'shown', 'HumidMoy' => 'nimporte'], 'series' => ['Humid1' => 'shown']],
            'pas-un-bloc',
        ]]);

        $this->assertSame([['id' => 'sol', 'cards' => [], 'series' => ['Humid1' => 'shown']]], $layout['blocks']);
        $this->assertSame(['blocks' => []], $this->resolver->normalize($this->catalog, 'garbage'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidLayouts(): iterable
    {
        yield 'pas de blocks' => [['foo' => 1]];
        yield 'blocks non liste' => [['blocks' => ['a' => ['id' => 'sol']]]];
        yield 'bloc inconnu' => [['blocks' => [['id' => 'inconnu']]]];
        yield 'bloc en double' => [['blocks' => [['id' => 'sol'], ['id' => 'sol']]]];
        yield 'visible non booléen' => [['blocks' => [['id' => 'sol', 'visible' => 'oui']]]];
        yield 'état de série invalide' => [['blocks' => [['id' => 'sol', 'series' => ['HumidMoy' => 'off']]]]];
        yield 'carte inconnue' => [['blocks' => [['id' => 'sol', 'cards' => ['Inconnue' => 'shown']]]]];
        yield 'cartes en liste' => [['blocks' => [['id' => 'sol', 'cards' => ['HumidMoy']]]]];
    }

    /**
     * @dataProvider invalidLayouts
     */
    public function testStrictNormalizationRejectsInvalidLayouts(mixed $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->normalize($this->catalog, $raw, true);
    }

    public function testNewCatalogElementsAreInsertedAfterTheirPredecessor(): void
    {
        $this->assertSame(
            ['c', 'd', 'a', 'b'],
            $this->resolver->mergeOrder(['a', 'b', 'c', 'd'], ['c', 'a']),
            'b suit a, d suit c'
        );
        $this->assertSame(['x', 'b', 'a'], $this->resolver->mergeOrder(['x', 'a', 'b'], ['b', 'a']));
        $this->assertSame(['a', 'b'], $this->resolver->mergeOrder(['a', 'b'], ['zzz']));
    }

    public function testExpandListsEveryCatalogElementWithExplicitStates(): void
    {
        $expanded = $this->resolver->expand($this->catalog, ['blocks' => [['id' => 'systeme', 'visible' => false]]]);

        $this->assertSame(['sol', 'physique', 'systeme', 'capteur', 'filtrage'], array_column($expanded, 'id'));
        $this->assertFalse($expanded[2]['visible']);
        $this->assertTrue($expanded[0]['visible']);
        $sol = $expanded[0];
        $this->assertSame('legend', $sol['series']['Humid1']);
        $this->assertSame('shown', $sol['cards']['etatPompe']);
    }

    public function testSimpleBuiltInPresetHidesTechnicalBlocks(): void
    {
        $simple = $this->catalog->builtInPresets()[PageCatalog::PRESET_SIMPLE];
        $resolved = $this->resolver->resolve($this->catalog, $this->resolver->normalize($this->catalog, $simple['layout'], true));

        $this->assertSame(['sol', 'physique', 'filtrage'], array_column($resolved['blocks'], 'id'));
        $this->assertSame(['HumidMoy', 'etatPompe'], array_column($resolved['blocks'][0]['cards'], 'key'));
    }
}
