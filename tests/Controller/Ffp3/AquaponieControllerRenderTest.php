<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Config\TableConfig;
use App\Controller\Ffp3\AquaponieController;
use App\Display\LayoutResolver;
use App\Display\PageCatalogRegistry;
use App\Repository\DisplayPresetRepository;
use App\Repository\SensorReadRepository;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\DisplayPresetService;
use App\Service\LogService;
use App\Service\StatisticsAggregatorService;
use App\Service\TemplateRenderer;
use App\Service\TideCycleDetector;
use App\Service\WaterBalanceService;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Rendu réel (Twig) de la page de suivi aquaponie : régressions d'affichage 6.46.0
 * corrigées en 6.49.0 (marées, chimie, actionneurs, défaut « étendu »).
 */
final class AquaponieControllerRenderTest extends TestCase
{
    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(bool $aquariumSilent = false): array
    {
        $rows = [];
        foreach (['2026-10-30 10:00:00', '2026-10-30 10:02:00', '2026-10-30 10:04:00'] as $i => $time) {
            $rows[] = [
                'reading_time' => $time,
                // Niveaux stockés en mm (convertis en cm par le contrôleur).
                'EauAquarium' => $aquariumSilent ? null : 170 + 10 * $i, 'EauReserve' => 420, 'EauPotager' => 150,
                'TempEau' => 23.4, 'TempAir' => 25.1, 'Humidite' => 71, 'Luminosite' => 1800,
                'etatPompeAqua' => 1, 'etatPompeTank' => 0, 'etatHeat' => $i % 2, 'etatUV' => 1,
                'bouffePetits' => 0, 'bouffeGros' => 0, 'sensor' => 'ffp3', 'version' => '15.31',
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, string> $cookies
     */
    private function render(string $uri, array $cookies = [], bool $aquariumSilent = false): ResponseInterface
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        TableConfig::setEnvironment('test');
        $rows = $this->rows($aquariumSilent);
        $last = '2026-10-30 10:04:00';

        $repo = $this->createMock(SensorReadRepository::class);
        $repo->method('getLastReadingDate')->willReturn($last);
        $repo->method('fetchBetween')->willReturn($rows);
        $repo->method('getLastReadings')->willReturn($rows[2]);
        $repo->method('getFirstReadingDate')->willReturn('2026-01-01 00:00:00');
        $repo->method('getFirmwareVersion')->willReturn('15.31');
        // Dernière valeur valide (brute, en mm) : 81 mm = 8,1 cm le 31/10 (cas réel de production).
        $repo->method('getLastNonNull')->willReturnCallback(
            static fn (string $column, string $notBefore = ''): ?array => $column === 'EauAquarium'
                ? ['value' => 81.0, 'reading_time' => '2026-10-29 21:07:41']
                : null
        );

        $stats = $this->createMock(StatisticsAggregatorService::class);
        $stats->method('aggregateAllStats')->willReturn([]);
        $stats->method('flattenForLegacy')->willReturn(['avg_tempeau' => 23.4, 'min_tempeau' => 23.0, 'max_tempeau' => 23.8]);

        $balance = $this->createMock(WaterBalanceService::class);
        $balance->method('computeBalance')->willReturn([
            'reserve_consumption' => 0.5, 'reserve_refill' => 0.0, 'reserve_balance' => -0.5,
            'tide_frequency' => 2.0, 'tide_marnage' => 3.1, 'tide_marnage_stddev' => 0.2, 'tide_cycles' => 4,
            'tide_trend' => 'rising', 'tide_trend_label' => 'Montante', 'tide_threshold_cm' => 2,
            'aquarium_consumption' => 0.3, 'aquarium_consumption_per_day' => 0.1,
        ]);

        $tide = $this->createMock(TideCycleDetector::class);
        $tide->method('detectExtremaSeries')->willReturn([
            'peaks' => [[1793354520000, 19.0]],
            'troughs' => [[1793354400000, 17.0]],
        ]);

        $dateRange = $this->createMock(DateRangeExtractor::class);
        $dateRange->method('extract')->willReturn(['2026-10-30 10:00:00', $last]);
        $dateRange->method('resolveDataWindow')->willReturn([
            'start' => '2026-10-30 10:00:00', 'end' => $last, 'mode' => 'default', 'window' => null,
            'offset' => 0, 'has_prev' => true, 'has_next' => false, 'prev_offset' => null,
            'next_offset' => null, 'width_seconds' => 240,
        ]);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $presets = new DisplayPresetService(
            new DisplayPresetRepository($pdo),
            new PageCatalogRegistry(),
            new LayoutResolver(),
            $this->createMock(LogService::class)
        );

        $controller = new AquaponieController(
            $repo,
            $stats,
            new ChartDataService(),
            $balance,
            $tide,
            new TemplateRenderer(dirname(__DIR__, 3) . '/templates', false),
            $this->createMock(LogService::class),
            $dateRange,
            $this->createMock(CsvExportService::class),
            $presets,
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri)->withCookieParams($cookies);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $controller->showAlt($request->withQueryParams($query), (new ResponseFactory())->createResponse());
    }

    public function testDefaultViewIsExtendedWithTideMarkersChemistryAndActuators(): void
    {
        $response = $this->render('/aquaponie-test');
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), $html);
        $this->assertStringContainsString('data-preset="etendu"', $html, 'défaut « étendu » pour tous les visiteurs');
        // Marqueurs de marée : ajoutés via onReady (après la création différée des graphiques).
        $this->assertStringContainsString('n3LayoutCharts.onReady', $html);
        $this->assertStringContainsString('aquaponie-tide-markers.js', $html);
        $this->assertStringContainsString('"chart-ffp3-eau"', $html);
        // Section chimie (analyses des élèves) de retour.
        $this->assertStringContainsString('Suivi des paramètres chimiques', $html);
        $this->assertStringContainsString('pubchart?oid=699943208&amp;format=interactive', $html);
        // Cartes d'actionneurs lisibles et mises à jour en live.
        $this->assertStringContainsString('data-sensor="etatUV" data-format="onoff">Marche<', $html);
        $this->assertStringContainsString('data-sensor="etatPompeTank" data-format="onoff">Arrêt<', $html);
        $this->assertStringContainsString('>Lumière<', $html);
        // Deux pastilles seulement (doublons intégrés masqués).
        $this->assertSame(1, substr_count($html, 'data-preset="simple"'));
        $this->assertStringNotContainsString('data-preset="visite"', $html);
        $this->assertStringNotContainsString('data-preset="technique"', $html);
    }

    public function testRememberedPresetCookieRendersSimpleViewDirectly(): void
    {
        $html = (string) $this->render('/aquaponie-test', ['n3_preset_ffp3' => 'simple'])->getBody();

        $this->assertStringContainsString('data-preset="simple"', $html);
        $this->assertStringContainsString('aria-current="page">Simple<', $html);
        $this->assertStringNotContainsString('chart-ffp3-actuators', $html, 'actionneurs masqués en Simple');
        $this->assertStringContainsString('Suivi des paramètres chimiques', $html, 'chimie gardée en Simple');
    }

    public function testSilentAquariumSensorShowsItsLastValidValue(): void
    {
        $html = (string) $this->render('/aquaponie-test', [], true)->getBody();

        $this->assertStringContainsString('data-last-valid="EauAquarium"', $html);
        $this->assertStringContainsString('Dernière valeur valide :', $html);
        $this->assertMatchesRegularExpression('/8,1 cm\s+le 29\/10\/2026 à \d\d:07/', $html, 'mm convertis en cm');
        $this->assertStringNotContainsString('data-last-valid="EauReserve"', $html);
        // Payload : points « réchauffés » au réveil et tendances (linéaires, moyennes mobiles).
        $this->assertStringContainsString('"stale":', $html);
        $this->assertStringContainsString('"trendKind":"moving_avg"', $html);
        $this->assertStringContainsString('"id":"trend-EauReserve"', $html);
    }
}
