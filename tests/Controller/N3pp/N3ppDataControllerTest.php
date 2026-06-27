<?php

declare(strict_types=1);

namespace Tests\Controller\N3pp;

use App\Controller\N3pp\N3ppDataController;
use App\Repository\N3ppSensorRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Garde-fou sur l'ordre temporel des séries fournies au template N3PP.
 *
 * Highstock exige un axe X strictement croissant : si les timestamps sont
 * décroissants, la bibliothèque émet l'avertissement #15 (« data not sorted in
 * ascending X order ») et n'affiche pas correctement les courbes.
 *
 * Le repository renvoie les lectures en ASC (cf. AbstractSensorRepository::fetchBetween)
 * et le contrôleur doit préserver cet ordre jusqu'au template.
 */
final class N3ppDataControllerTest extends TestCase
{
    /**
     * Capture le contexte passé au template et vérifie que `reading_time` est
     * croissant, reproduisant le flux complet du contrôleur de données N3PP.
     */
    public function testReadingTimeSeriesIsAscendingForHighstock(): void
    {
        // Lectures en ordre chronologique (ASC), comme fetchBetween() les renvoie.
        $readings = [
            ['reading_time' => '2025-10-10 08:00:00', 'TempAir' => 18.0, 'Humidite' => 60, 'Luminosite' => 100,
                'Humid1' => 10, 'Humid2' => 11, 'Humid3' => 12, 'Humid4' => 13, 'HumidMoy' => 11,
                'PontDiv' => 900, 'bootCount' => 1, 'etatPompe' => 0, 'resetMode' => 0],
            ['reading_time' => '2025-10-10 09:00:00', 'TempAir' => 19.0, 'Humidite' => 61, 'Luminosite' => 200,
                'Humid1' => 14, 'Humid2' => 15, 'Humid3' => 16, 'Humid4' => 17, 'HumidMoy' => 15,
                'PontDiv' => 905, 'bootCount' => 1, 'etatPompe' => 1, 'resetMode' => 0],
            ['reading_time' => '2025-10-10 10:00:00', 'TempAir' => 20.0, 'Humidite' => 62, 'Luminosite' => 300,
                'Humid1' => 18, 'Humid2' => 19, 'Humid3' => 20, 'Humid4' => 21, 'HumidMoy' => 19,
                'PontDiv' => 910, 'bootCount' => 1, 'etatPompe' => 0, 'resetMode' => 0],
        ];

        $sensorRepo = $this->createMock(N3ppSensorRepository::class);
        $sensorRepo->method('getLastReadingDate')->willReturn('2025-10-10 10:00:00');
        $sensorRepo->method('fetchBetween')->willReturn($readings);
        $sensorRepo->method('getLatest')->willReturn($readings[2]);
        $sensorRepo->method('getFirstReadingDate')->willReturn('2025-10-10 08:00:00');
        $sensorRepo->method('getFirmwareVersion')->willReturn('v1.0');

        $dateRange = $this->createMock(DateRangeExtractor::class);
        $dateRange->method('extract')->willReturn(['2025-10-10 08:00:00', '2025-10-10 10:00:00']);
        $dateRange->method('resolveDataWindow')->willReturn([
            'start' => '2025-10-10 08:00:00',
            'end' => '2025-10-10 10:00:00',
            'mode' => 'default',
            'window' => null,
        ]);

        $capturedContext = null;
        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->method('render')->willReturnCallback(
            function (string $template, array $context) use (&$capturedContext): string {
                $capturedContext = $context;
                return '';
            }
        );

        $controller = new N3ppDataController(
            $renderer,
            $sensorRepo,
            $this->createMock(CsrfService::class),
            $dateRange,
            $this->createMock(CsvExportService::class),
            new ChartDataService(),
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/serre');
        $response = (new ResponseFactory())->createResponse();

        $controller->show($request, $response);

        $this->assertIsArray($capturedContext);
        $this->assertArrayHasKey('reading_time', $capturedContext);

        $timestamps = $capturedContext['reading_time'];
        $this->assertCount(3, $timestamps);

        // Cœur du garde-fou : ordre temporel strictement croissant (anti Highcharts #15).
        for ($i = 1, $n = count($timestamps); $i < $n; $i++) {
            $this->assertGreaterThan(
                $timestamps[$i - 1],
                $timestamps[$i],
                'Les timestamps doivent être croissants pour Highstock (évite l\'avertissement #15).'
            );
        }

        // Les valeurs restent alignées avec les timestamps (ordre ASC préservé).
        $this->assertSame([18.0, 19.0, 20.0], $capturedContext['TempAir']);
    }
}
