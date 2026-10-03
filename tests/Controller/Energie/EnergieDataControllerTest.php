<?php

declare(strict_types=1);

namespace Tests\Controller\Energie;

use App\Config\TableConfig;
use App\Controller\Energie\EnergieDataController;
use App\Repository\EnergieSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Page `/energie[-test]` : contexte transmis au template, cohérence
 * graphiques ↔ sensor map ↔ cartes, et rendu Twig réel de energie_data.twig
 * (ordre des séries JS = index de la sensor map utilisée par le live).
 */
final class EnergieDataControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readings(): array
    {
        $base = [
            'id' => 1, 'sensor' => 'energie', 'version' => '0.3.1',
            'PanneauV' => 18.0, 'PanneauI' => 1.0, 'PanneauP' => 18.0, 'PanneauImax' => 1.2,
            'BatterieV' => 12.8, 'BatterieVmin' => 12.7, 'BatterieI' => -0.5, 'BatterieImin' => -0.8,
            'BatterieImax' => 0.0, 'BatterieP' => -6.4, 'BatterieVadc' => 12.75,
            'ConsoV' => 12.6, 'ConsoI' => 0.5, 'ConsoP' => 6.3, 'ConsoImax' => 0.6,
            'EnergiePanneauWh' => 0.05, 'EnergieConsoWh' => 0.0175, 'BatterieAh' => -1.2, 'BatterieSoc' => 76.0,
            'InaStatus' => 0x107, 'I2cErreurs' => 0, 'Rssi' => -60, 'FreeHeap' => 180000, 'BootCount' => 3, 'Uptime' => 7200,
        ];

        $rows = [];
        foreach (['2026-10-03 10:00:00', '2026-10-03 10:00:10', '2026-10-03 10:00:20'] as $i => $time) {
            $rows[] = array_merge($base, ['id' => $i + 1, 'reading_time' => $time, 'PanneauP' => 18.0 + $i]);
        }

        return $rows;
    }

    private function controller(TemplateRenderer $renderer): EnergieDataController
    {
        $readings = $this->readings();

        $sensorRepo = $this->createMock(EnergieSensorRepository::class);
        $sensorRepo->method('getLastReadingDate')->willReturn('2026-10-03 10:00:20');
        $sensorRepo->method('fetchBetween')->willReturn($readings);
        $sensorRepo->method('getLatest')->willReturn($readings[2]);
        $sensorRepo->method('getFirstReadingDate')->willReturn('2026-10-03 10:00:00');
        $sensorRepo->method('getFirmwareVersion')->willReturn('0.3.1');

        $dateRange = $this->createMock(DateRangeExtractor::class);
        $dateRange->method('extract')->willReturn(['2026-10-03 10:00:00', '2026-10-03 10:00:20']);
        $dateRange->method('resolveDataWindow')->willReturn([
            'start' => '2026-10-03 10:00:00',
            'end' => '2026-10-03 10:00:20',
            'mode' => 'default',
            'window' => null,
        ]);

        return new EnergieDataController(
            $renderer,
            $sensorRepo,
            $this->createMock(CsrfService::class),
            $dateRange,
            $this->createMock(CsvExportService::class),
            new ChartDataService(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function captureContext(string $path): array
    {
        $captured = null;
        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->method('render')->willReturnCallback(
            function (string $template, array $context) use (&$captured): string {
                $this->assertSame('energie_data.twig', $template);
                $captured = $context;
                return '';
            }
        );

        $this->controller($renderer)->show(
            (new ServerRequestFactory())->createServerRequest('GET', $path),
            (new ResponseFactory())->createResponse()
        );
        $this->assertIsArray($captured);

        return $captured;
    }

    public function testTestEnvironmentContext(): void
    {
        TableConfig::setEnvironment('energie_test');
        $ctx = $this->captureContext('/energie-test');

        $this->assertSame('energie_test', $ctx['environment']);
        $this->assertSame('energie', $ctx['nav_active']);
        $this->assertStringEndsWith('(TEST)', $ctx['page_title']);
        $this->assertSame('/energie-test/api/realtime', $ctx['realtime_api_base']);
        $this->assertSame('/energie-test', $ctx['data_config']['form_action']);
        $this->assertSame('energieDataTest', $ctx['data_config']['table_label']);
        $this->assertSame('0.3.1', $ctx['firmware_version']);
        $this->assertSame(3, $ctx['measure_count']);
        $this->assertSame(
            ['chart-puissances', 'chart-tensions', 'chart-courants', 'chart-batterie'],
            json_decode($ctx['chart_ids_json'], true)
        );

        // Séries Highstock : timestamps croissants, valeurs alignées.
        $ts = $ctx['reading_time'];
        $this->assertCount(3, $ts);
        $this->assertLessThan($ts[1], $ts[0]);
        $this->assertLessThan($ts[2], $ts[1]);
        $this->assertSame([18.0, 19.0, 20.0], $ctx['PanneauP']);
        $this->assertSame([-0.5, -0.5, -0.5], $ctx['BatterieI']);

        // Statistiques (clé lcfirst) disponibles pour les cartes.
        $this->assertEqualsWithDelta(19.0, $ctx['avg_panneauP'], 1e-9);
        $this->assertEqualsWithDelta(-6.4, $ctx['min_batterieP'], 1e-9);
        $this->assertArrayHasKey('max_i2cErreurs', $ctx);
    }

    public function testProdEnvironmentContext(): void
    {
        TableConfig::setEnvironment('prod');
        $ctx = $this->captureContext('/energie');

        $this->assertSame('prod', $ctx['environment']);
        $this->assertStringNotContainsString('(TEST)', $ctx['page_title']);
        $this->assertSame('/energie/api/realtime', $ctx['realtime_api_base']);
        $this->assertSame('/energie', $ctx['data_config']['form_action']);
        $this->assertSame('energieData', $ctx['data_config']['table_label']);
    }

    public function testChartsSensorMapAndCardsAreCoherent(): void
    {
        TableConfig::setEnvironment('energie_test');
        $ctx = $this->captureContext('/energie-test');

        $chartIds = array_column($ctx['charts_config'], 'id');
        $map = json_decode($ctx['sensor_map_json'], true);
        $this->assertIsArray($map, 'sensor_map_json doit être un JSON valide');

        // Chaque série live est une colonne de graphique, et chaque colonne a une série.
        $this->assertEqualsCanonicalizing($ctx['chart_columns'], array_keys($map));

        // Index de série contigus 0..n-1 par graphique, et nombre = légende.
        foreach ($ctx['charts_config'] as $chart) {
            $indexes = [];
            foreach ($map as $entry) {
                if ($entry['chartId'] === $chart['id']) {
                    $indexes[] = $entry['seriesIndex'];
                }
            }
            sort($indexes);
            $this->assertSame(range(0, count($chart['legend_items']) - 1), $indexes, "séries de {$chart['id']}");
        }
        foreach ($map as $entry) {
            $this->assertContains($entry['chartId'], $chartIds);
        }

        // Chaque carte est rattachée à un graphique existant ou à la section Système,
        // et vise une colonne réelle de la table.
        $columns = (new EnergieSensorRepository(new \PDO('sqlite::memory:')))->getSensorColumns();
        $keys = [];
        foreach ($ctx['sensors_config'] as $sensor) {
            $this->assertContains($sensor['section'], [...$chartIds, 'systeme'], $sensor['key']);
            $this->assertContains($sensor['key'], $columns);
            $keys[] = $sensor['key'];
        }
        foreach (['PanneauV', 'PanneauI', 'PanneauP', 'BatterieV', 'BatterieI', 'BatterieP', 'BatterieSoc',
            'BatterieAh', 'ConsoV', 'ConsoI', 'ConsoP', 'EnergiePanneauWh', 'EnergieConsoWh', 'Rssi', 'I2cErreurs'] as $expected) {
            $this->assertContains($expected, $keys, "carte live {$expected}");
        }
    }

    /**
     * Rendu Twig réel : le template compile, affiche les cartes live et crée les
     * séries de chaque graphique dans l'ordre exact de la sensor map.
     */
    public function testTemplateRendersSeriesInSensorMapOrder(): void
    {
        TableConfig::setEnvironment('energie_test');
        $renderer = new TemplateRenderer(dirname(__DIR__, 3) . '/templates', false);
        $response = $this->controller($renderer)->show(
            (new ServerRequestFactory())->createServerRequest('GET', '/energie-test'),
            (new ResponseFactory())->createResponse()
        );
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('data-sensor="PanneauP"', $html);
        $this->assertStringContainsString('data-sensor="I2cErreurs"', $html);
        $this->assertStringContainsString('action="/energie-test"', $html);
        $this->assertStringContainsString('"\/energie-test\/api\/realtime"', $html);
        $this->assertStringContainsString('Uptime (24 h)', $html);
        $this->assertStringContainsString('panneau OK', $html);
        $this->assertStringContainsString('(ré-init)', $html, 'bit 8 (ré-init panneau) décodé');

        $map = json_decode($this->captureContext('/energie-test')['sensor_map_json'], true);
        $this->assertIsArray($map);

        foreach (['chart-puissances', 'chart-tensions', 'chart-courants', 'chart-batterie'] as $chartId) {
            $this->assertStringContainsString('id="' . $chartId . '"', $html);

            $start = strpos($html, "createStockChart('{$chartId}'");
            $this->assertNotFalse($start, "graphique {$chartId} créé");
            $end = strpos($html, 'createStockChart(', $start + 1);
            if ($end === false) {
                $end = strpos($html, 'bootstrap.bindResize', $start);
            }
            $block = substr($html, $start, ($end !== false ? $end : strlen($html)) - $start);
            preg_match_all('/zipSeries\(reading_time, (\w+)\)/', $block, $m);

            $expected = [];
            foreach ($map as $sensor => $entry) {
                if ($entry['chartId'] === $chartId) {
                    $expected[$entry['seriesIndex']] = $sensor;
                }
            }
            ksort($expected);
            $this->assertSame(array_values($expected), $m[1], "ordre des séries de {$chartId}");
        }
    }
}
