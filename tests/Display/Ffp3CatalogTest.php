<?php

declare(strict_types=1);

namespace Tests\Display;

use App\Controller\AbstractDataController;
use App\Controller\Ffp3\AquaponieController;
use App\Display\Catalog\Ffp3Catalog;
use App\Display\LayoutResolver;
use App\Display\PageCatalog;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Page de suivi aquaponie (6.49.0) : garde-fous sur les régressions d'affichage
 * introduites par le passage au catalogue (6.46.0).
 */
final class Ffp3CatalogTest extends TestCase
{
    private Ffp3Catalog $catalog;
    private LayoutResolver $resolver;

    protected function setUp(): void
    {
        $this->catalog = new Ffp3Catalog();
        $this->resolver = new LayoutResolver();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePreset(string $slug): array
    {
        $layout = $this->catalog->builtInPresets()[$slug]['layout'];

        return $this->resolver->resolve($this->catalog, $this->resolver->normalize($this->catalog, $layout, true));
    }

    /**
     * @return array<string, mixed>
     */
    private function chart(string $id): array
    {
        foreach ($this->catalog->blocks() as $block) {
            if (($block['chart']['id'] ?? null) === $id) {
                return $block['chart'];
            }
        }
        $this->fail("Graphique {$id} absent du catalogue");
    }

    public function testActuatorStatesNeverShareAMeasurementAxis(): void
    {
        foreach ($this->catalog->blocks() as $block) {
            if ($block['chart'] === null) {
                continue;
            }
            foreach ($block['chart']['series'] as $series) {
                if (!in_array($series['column'], ['etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV', 'bouffePetits', 'bouffeGros'], true)) {
                    continue;
                }
                $this->assertNotNull($series['on_markers'], "{$block['id']}/{$series['id']} : marqueurs ON attendus, pas une ligne 0/1");
                $this->assertSame('scatter', $series['type']);
                $axis = $block['chart']['yAxis'][$series['yAxis']] ?? null;
                $this->assertIsArray($axis);
                $isEventsAxis = ($axis['visible'] ?? true) === false && ($axis['min'] ?? null) === 0 && ($axis['max'] ?? null) === 1;
                $isChronogram = isset($axis['categories']);
                $this->assertTrue($isEventsAxis || $isChronogram, "{$block['id']}/{$series['id']} : axe d'événements dédié attendu");
            }
        }
    }

    public function testPhysicalChartHasOneAxisPerQuantity(): void
    {
        $chart = $this->chart('chart-ffp3-temp');
        $axisOf = array_column($chart['series'], 'yAxis', 'column');

        $this->assertSame($axisOf['TempEau'], $axisOf['TempAir']);
        $this->assertCount(4, array_unique([$axisOf['TempEau'], $axisOf['Humidite'], $axisOf['Luminosite'], $axisOf['etatHeat']]));
    }

    public function testSeriesColorsAreDistinctWithinEachChart(): void
    {
        foreach ($this->catalog->blocks() as $block) {
            if ($block['chart'] === null) {
                continue;
            }
            $colors = array_map('strtolower', array_column($block['chart']['series'], 'color'));
            $this->assertSame(array_values(array_unique($colors)), $colors, "Couleurs en double dans {$block['chart']['id']}");
        }
    }

    public function testLightIsNotLabelledUv(): void
    {
        foreach ($this->catalog->blocks() as $block) {
            foreach ($block['cards'] as $card) {
                $this->assertStringNotContainsString('UV', $card['label']);
            }
            foreach ($block['chart']['series'] ?? [] as $series) {
                $this->assertStringNotContainsString('UV', $series['name'], 'etatUV = GPIO 15 Lumière (Ffp3GpioMap)');
            }
        }
    }

    public function testReserveLevelStartsUncheckedInTheLegend(): void
    {
        $series = array_column($this->resolvePreset(PageCatalog::PRESET_EXTENDED)['charts'][0]['series'], null, 'column');
        $this->assertTrue($series['EauAquarium']['visible']);
        $this->assertFalse($series['EauReserve']['visible']);
    }

    public function testChemistryBlockIsShownInEveryPresetEvenWithoutSensorData(): void
    {
        $block = $this->catalog->block('chimie');
        $this->assertNotNull($block);
        $this->assertFalse($block['requires_data']);
        $this->assertCount(3, $block['options']['sheets']);
        foreach ($block['options']['sheets'] as $sheet) {
            $this->assertStringStartsWith('https://docs.google.com/spreadsheets/', $sheet['src']);
        }
        foreach ([PageCatalog::PRESET_SIMPLE, PageCatalog::PRESET_EXTENDED] as $slug) {
            $this->assertContains('chimie', array_column($this->resolvePreset($slug)['blocks'], 'id'), $slug);
        }
    }

    public function testActuatorLiveTargetsCoverEveryChartAndUseLanes(): void
    {
        $map = $this->resolvePreset(PageCatalog::PRESET_EXTENDED)['sensor_map'];

        $this->assertArrayHasKey('etatHeat', $map);
        $charts = array_column($map['etatHeat']['targets'], 'chartId');
        $this->assertSame(['chart-ffp3-temp', 'chart-ffp3-actuators'], $charts);
        foreach ($map['etatHeat']['targets'] as $target) {
            $this->assertArrayHasKey('onLane', $target);
        }
        $this->assertArrayNotHasKey('onLane', $map['EauAquarium']);
    }

    public function testTideMarkersFollowTheAquariumSeries(): void
    {
        $this->assertSame('chart-ffp3-eau', AquaponieController::tideChartId($this->resolvePreset(PageCatalog::PRESET_EXTENDED)['charts']));

        $layout = $this->resolver->normalize($this->catalog, ['blocks' => [
            ['id' => 'niveaux', 'series' => ['EauAquarium' => 'hidden']],
        ]], true);
        $this->assertNull(AquaponieController::tideChartId($this->resolver->resolve($this->catalog, $layout)['charts']));
    }

    public function testChartPayloadCarriesMarkersAndStep(): void
    {
        $charts = $this->resolvePreset(PageCatalog::PRESET_EXTENDED)['charts'];
        $payload = AbstractDataController::chartPayload($charts, ['reading_time' => [1, 2], 'etatHeat' => [0.0, 1.0]]);

        $temp = array_column($payload['charts'], null, 'id')['chart-ffp3-temp'];
        $heat = array_column($temp['series'], null, 'column')['etatHeat'];
        $this->assertSame('etatHeat', $heat['id']);
        $this->assertSame(['lane' => 0.03, 'symbol' => 'triangle', 'radius' => 4], $heat['onMarkers']);
        $this->assertArrayHasKey('step', $heat);
    }

    public function testStepOptionIsKeptByTheCatalog(): void
    {
        $catalog = new class () extends PageCatalog {
            public function pageKey(): string
            {
                return 'demo';
            }

            public function label(): string
            {
                return 'Démo';
            }

            public function chartColumns(): array
            {
                return ['a'];
            }

            public function statsColumns(): array
            {
                return [];
            }

            public function pageConfig(string $environment): array
            {
                return [];
            }

            public function monitoringUrl(string $environment): string
            {
                return '/demo';
            }

            protected function defineBlocks(): array
            {
                return [['id' => 'b', 'chart' => ['id' => 'c', 'series' => [
                    ['column' => 'a', 'name' => 'A', 'type' => 'line', 'step' => true],
                    ['column' => 'b', 'name' => 'B', 'type' => 'line', 'step' => 'center'],
                    ['column' => 'c', 'name' => 'C'],
                ]]]];
            }
        };

        $series = $catalog->blocks()[0]['chart']['series'];
        $this->assertSame(['left', 'center', null], array_column($series, 'step'));
    }

    public function testRememberedPresetCookieIsValidated(): void
    {
        $factory = new ServerRequestFactory();
        $ok = $factory->createServerRequest('GET', '/aquaponie')->withCookieParams(['n3_preset_ffp3' => 'simple']);
        $bad = $factory->createServerRequest('GET', '/aquaponie')->withCookieParams(['n3_preset_ffp3' => '../etc']);
        $other = $factory->createServerRequest('GET', '/aquaponie')->withCookieParams(['n3_preset_msp1' => 'simple']);

        $this->assertSame('simple', AbstractDataController::rememberedPreset($ok, 'ffp3'));
        $this->assertNull(AbstractDataController::rememberedPreset($bad, 'ffp3'));
        $this->assertNull(AbstractDataController::rememberedPreset($other, 'ffp3'));
    }
}
