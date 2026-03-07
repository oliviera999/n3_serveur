<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TableConfig;
use App\Config\Version;
use App\Service\TemplateRenderer;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LocalDataPagesController
{
    public function __construct(
        private TemplateRenderer $renderer
    ) {
    }

    public function showAquaponie(Request $request, Response $response): Response
    {
        $context = $this->buildAquaponieContext('aquaponie_alt.twig');
        return $this->render($response, 'aquaponie_alt.twig', $context);
    }

    public function showAquaponieClassic(Request $request, Response $response): Response
    {
        $context = $this->buildAquaponieContext('aquaponie.twig');
        return $this->render($response, 'aquaponie.twig', $context);
    }

    public function showMsp1(Request $request, Response $response): Response
    {
        $this->logLocalFallback('msp1_data.twig');

        $range = $this->getDefaultRange('-24 hours');
        $series = [
            'reading_time' => [],
            'TempAirInt' => [], 'TempAirExt' => [],
            'HumidAirInt' => [], 'HumidAirExt' => [],
            'LuminositeMoy' => [], 'LuminositeA' => [], 'LuminositeB' => [], 'LuminositeC' => [], 'LuminositeD' => [],
            'HumidSol' => [], 'TempEau' => [], 'Pluie' => [],
            'PontDiv' => [], 'bootCount' => [],
            'ServoHB' => [], 'ServoGD' => [], 'resetMode' => [],
        ];

        $stats = [];
        foreach ([
            'tempAirInt', 'tempAirExt', 'humidAirInt', 'humidAirExt', 'luminositeMoy',
            'luminositeA', 'luminositeB', 'luminositeC', 'luminositeD',
            'humidSol', 'tempEau', 'pluie', 'pontDiv', 'bootCount',
            'servoHB', 'servoGD', 'resetMode',
        ] as $suffix) {
            $stats["avg_$suffix"] = null;
            $stats["min_$suffix"] = null;
            $stats["max_$suffix"] = null;
            $stats["stddev_$suffix"] = null;
        }

        return $this->render($response, 'msp1_data.twig', array_merge([
            'page_title' => 'Données station météo - Le potager',
            'latest' => null,
            'board' => 2,
            'version' => Version::getWithPrefix(),
            'firmware_version' => '-',
            'environment' => TableConfig::getEnvironment(),
            'start_date' => $range['start'],
            'end_date' => $range['end'],
            'measure_count' => 0,
            'duration_str' => '0 j, 0 h, 0 min',
        ], $series, $stats));
    }

    public function showN3pp(Request $request, Response $response): Response
    {
        $this->logLocalFallback('n3pp_data.twig');

        $range = $this->getDefaultRange('-24 hours');
        $series = [
            'reading_time' => [],
            'TempAir' => [], 'Humidite' => [], 'Luminosite' => [],
            'Humid1' => [], 'Humid2' => [], 'Humid3' => [], 'Humid4' => [], 'HumidMoy' => [],
            'PontDiv' => [], 'bootCount' => [],
            'etatPompe' => [], 'resetMode' => [],
        ];

        $stats = [];
        foreach ([
            'tempAir', 'humidite', 'luminosite',
            'humid1', 'humid2', 'humid3', 'humid4', 'humidMoy',
            'pontDiv', 'bootCount', 'etatPompe',
        ] as $suffix) {
            $stats["avg_$suffix"] = null;
            $stats["min_$suffix"] = null;
            $stats["max_$suffix"] = null;
            $stats["stddev_$suffix"] = null;
        }

        return $this->render($response, 'n3pp_data.twig', array_merge([
            'page_title' => 'Données serre / élevage - n3 iot',
            'latest' => null,
            'board' => 3,
            'version' => Version::getWithPrefix(),
            'firmware_version' => '-',
            'environment' => TableConfig::getEnvironment(),
            'start_date' => $range['start'],
            'end_date' => $range['end'],
            'measure_count' => 0,
            'duration_str' => '0 j, 0 h, 0 min',
        ], $series, $stats));
    }

    private function buildAquaponieContext(string $template): array
    {
        $this->logLocalFallback($template);

        $range = $this->getDefaultRange('-6 hours');

        return [
            'page_title' => 'Aquaponie - mode local sans base',
            'start_date' => $range['start'],
            'end_date' => $range['end'],
            'reading_time' => '[]',
            'measure_count' => 0,
            'duration_str' => '0 jours, 0 heures, 0 minutes, 0 secondes',
            'version' => Version::getWithPrefix(),
            'firmware_version' => '-',
            'environment' => TableConfig::getEnvironment(),
            'last_reading_tempair' => 0,
            'last_reading_tempeau' => 0,
            'last_reading_humi' => 0,
            'last_reading_lumi' => 0,
            'last_reading_eauaqua' => 0,
            'last_reading_eaureserve' => 0,
            'last_reading_eaupota' => 0,
            'min_tempair' => null,
            'max_tempair' => null,
            'avg_tempair' => null,
            'stddev_tempair' => null,
            'min_tempeau' => null,
            'max_tempeau' => null,
            'avg_tempeau' => null,
            'stddev_tempeau' => null,
            'min_humi' => null,
            'max_humi' => null,
            'avg_humi' => null,
            'stddev_humi' => null,
            'min_lumi' => null,
            'max_lumi' => null,
            'avg_lumi' => null,
            'stddev_lumi' => null,
            'min_eauaqua' => null,
            'max_eauaqua' => null,
            'avg_eauaqua' => null,
            'stddev_eauaqua' => null,
            'min_eaureserve' => null,
            'max_eaureserve' => null,
            'avg_eaureserve' => null,
            'stddev_eaureserve' => null,
            'min_eaupota' => null,
            'max_eaupota' => null,
            'avg_eaupota' => null,
            'stddev_eaupota' => null,
            'reserve_consumption' => null,
            'reserve_refill' => null,
            'reserve_balance' => null,
            'tide_frequency' => null,
            'tide_frequency_stddev' => null,
            'tide_marnage' => null,
            'tide_marnage_stddev' => null,
            'tide_cycles' => 0,
            'aquarium_consumption' => null,
            'EauAquarium' => '[]',
            'EauReserve' => '[]',
            'EauPotager' => '[]',
            'TempAir' => '[]',
            'TempEau' => '[]',
            'Humidite' => '[]',
            'Luminosite' => '[]',
            'etatPompeAqua' => '[]',
            'etatPompeTank' => '[]',
            'etatHeat' => '[]',
            'etatUV' => '[]',
            'bouffePetits' => '[]',
            'bouffeGros' => '[]',
        ];
    }

    private function render(Response $response, string $template, array $context): Response
    {
        $html = $this->renderer->render($template, $context);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function getDefaultRange(string $relativeStart): array
    {
        $end = new DateTimeImmutable();
        $start = $end->modify($relativeStart);

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];
    }

    private function logLocalFallback(string $template): void
    {
        unset($template);
    }
}
