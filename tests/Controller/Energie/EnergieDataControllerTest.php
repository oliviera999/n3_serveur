<?php

declare(strict_types=1);

namespace Tests\Controller\Energie;

use App\Config\TableConfig;
use App\Controller\Energie\EnergieDataController;
use App\Display\LayoutResolver;
use App\Display\PageCatalogRegistry;
use App\Repository\DisplayPresetRepository;
use App\Repository\EnergieSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\DisplayPresetService;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Page `/energie[-test]` : contexte catalogue + preset, cohérence sensor map
 * et rendu Twig (cartes live + payload graphiques).
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

    private function displayPresets(): DisplayPresetService
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new DisplayPresetService(
            new DisplayPresetRepository($pdo),
            new PageCatalogRegistry(),
            new LayoutResolver(),
            $this->createMock(LogService::class)
        );
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
            'offset' => 0,
            'has_prev' => true,
            'has_next' => false,
            'prev_offset' => null,
            'next_offset' => null,
            'width_seconds' => 20,
        ]);

        return new EnergieDataController(
            $renderer,
            $sensorRepo,
            $this->createMock(CsrfService::class),
            $dateRange,
            $this->createMock(CsvExportService::class),
            new ChartDataService(),
            $this->displayPresets(),
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
        $this->assertSame('etendu', $ctx['preset']['slug']);
        $this->assertSame(
            ['chart-puissances', 'chart-tensions', 'chart-courants', 'chart-batterie'],
            json_decode($ctx['chart_ids_json'], true)
        );

        $payload = $ctx['chart_payload'];
        $this->assertCount(3, $payload['readingTime']);
        $this->assertLessThan($payload['readingTime'][1], $payload['readingTime'][0]);
        $this->assertSame([18.0, 19.0, 20.0], (array) $payload['columns']->PanneauP);
        $this->assertSame([-0.5, -0.5, -0.5], (array) $payload['columns']->BatterieI);

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

    public function testLayoutSensorMapAndCardsAreCoherent(): void
    {
        TableConfig::setEnvironment('energie_test');
        $ctx = $this->captureContext('/energie-test');

        $chartIds = array_column($ctx['layout']['charts'], 'id');
        $map = json_decode($ctx['sensor_map_json'], true);
        $this->assertIsArray($map, 'sensor_map_json doit être un JSON valide');
        $this->assertSame($ctx['layout']['sensor_map'], $map);

        foreach ($ctx['layout']['charts'] as $chart) {
            $indexes = [];
            foreach ($map as $entry) {
                if ($entry['chartId'] === $chart['id']) {
                    $indexes[] = $entry['seriesIndex'];
                }
            }
            sort($indexes);
            $liveCount = count(array_filter($chart['series'], static fn (array $s): bool => ($s['live'] ?? false) === true));
            $this->assertSame(range(0, $liveCount - 1), $indexes, "séries live de {$chart['id']}");
        }

        $cardKeys = [];
        foreach ($ctx['layout']['blocks'] as $block) {
            foreach ($block['cards'] as $card) {
                $cardKeys[] = $card['key'];
            }
        }
        foreach (['PanneauV', 'PanneauI', 'PanneauP', 'BatterieV', 'BatterieI', 'BatterieP', 'BatterieSoc',
            'BatterieAh', 'ConsoV', 'ConsoI', 'ConsoP', 'EnergiePanneauWh', 'EnergieConsoWh', 'Rssi', 'I2cErreurs'] as $expected) {
            $this->assertContains($expected, $cardKeys, "carte live {$expected}");
        }
        $this->assertContains('chart-puissances', $chartIds);
    }

    public function testTemplateRendersLiveCardsAndChartPayload(): void
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
        $this->assertStringContainsString('n3LayoutCharts', $html);
        $this->assertStringContainsString('chart-puissances', $html);
        $this->assertStringContainsString('preset-selector', $html);
        $this->assertMatchesRegularExpression('/Uptime \(24 h\)|Uptime \(30j\)/', $html);
        $this->assertStringContainsString('panneau OK', $html);
        $this->assertStringContainsString('(ré-init)', $html, 'bit 8 (ré-init panneau) décodé');
        $this->assertSame('Uptime (24 h)', $this->captureContext('/energie-test')['data_config']['uptime_label'] ?? null);
    }

    public function testSimplePresetHidesTechnicalBlocks(): void
    {
        TableConfig::setEnvironment('prod');
        $ctx = $this->captureContext('/energie?preset=simple');

        $this->assertSame('simple', $ctx['preset']['slug']);
        $blockIds = array_column($ctx['layout']['blocks'], 'id');
        $this->assertNotContains('systeme', $blockIds);
    }
}
