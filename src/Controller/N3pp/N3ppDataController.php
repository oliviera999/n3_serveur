<?php

declare(strict_types=1);

namespace App\Controller\N3pp;

use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\N3ppSensorRepository;
use App\Security\CsrfService;
use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class N3ppDataController
{
    private const BOARD = 3;

    public function __construct(
        private TemplateRenderer $renderer,
        private N3ppSensorRepository $sensorRepo,
        private CsrfService $csrfService,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $lastDate = $this->sensorRepo->getLastReadingDate();
        $defaultEnd = $lastDate ?: date('Y-m-d H:i:s');
        $defaultStart = date('Y-m-d H:i:s', strtotime($defaultEnd . ' -24 hours'));

        [$startDate, $endDate] = $this->extractDateRange($request, $defaultStart, $defaultEnd);

        $body = $request->getParsedBody() ?? [];
        if (isset($body['export_csv'])) {
            return $this->exportCsv($startDate, $endDate, $response);
        }

        $readings = $this->sensorRepo->fetchBetween($startDate, $endDate);
        $measureCount = count($readings);

        $chartData = $this->prepareChartData($readings);
        $latest = $this->sensorRepo->getLatest();
        $firmwareVersion = $this->sensorRepo->getFirmwareVersion();

        $statsColumns = [
            'TempAir', 'Humidite', 'Luminosite',
            'Humid1', 'Humid2', 'Humid3', 'Humid4', 'HumidMoy',
            'PontDiv', 'bootCount', 'etatPompe',
        ];
        $stats = [];
        foreach ($statsColumns as $col) {
            $s = $this->sensorRepo->getColumnStats($col, $startDate, $endDate);
            $lc = lcfirst($col);
            $stats["avg_$lc"] = $s['avg'];
            $stats["min_$lc"] = $s['min'];
            $stats["max_$lc"] = $s['max'];
            $stats["stddev_$lc"] = $s['stddev'];
        }

        $durationSec = strtotime($endDate) - strtotime($startDate);
        $days = (int) floor($durationSec / 86400);
        $hours = (int) floor(($durationSec % 86400) / 3600);
        $minutes = (int) floor(($durationSec % 3600) / 60);

        $html = $this->renderer->render('n3pp_data.twig', array_merge([
            'page_title' => 'Données serre / élevage - n3 iot',
            'latest' => $latest,
            'board' => self::BOARD,
            'version' => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment' => TableConfig::getEnvironment(),
            'csrf_field' => $this->csrfService->getHiddenField(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'measure_count' => $measureCount,
            'duration_str' => "$days j, $hours h, $minutes min",
        ], $chartData, $stats));

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function extractDateRange(Request $request, string $defaultStart, string $defaultEnd): array
    {
        if ($request->getMethod() !== 'POST') {
            return [$defaultStart, $defaultEnd];
        }
        $body = $request->getParsedBody() ?? [];

        $token = $body['_csrf_token'] ?? null;
        if (!$this->csrfService->validateToken($token)) {
            return [$defaultStart, $defaultEnd];
        }

        $sd = $body['start_datetime'] ?? null;
        $ed = $body['end_datetime'] ?? null;
        if ($sd && $ed) {
            return [
                str_replace('T', ' ', $sd) . ':00',
                str_replace('T', ' ', $ed) . ':00',
            ];
        }
        return [$defaultStart, $defaultEnd];
    }

    /**
     * @param array<int, array<string, mixed>> $readings
     * @return array<string, mixed>
     */
    private function prepareChartData(array $readings): array
    {
        $series = [
            'reading_time' => [],
            'TempAir' => [], 'Humidite' => [], 'Luminosite' => [],
            'Humid1' => [], 'Humid2' => [], 'Humid3' => [], 'Humid4' => [], 'HumidMoy' => [],
            'PontDiv' => [], 'bootCount' => [],
            'etatPompe' => [], 'resetMode' => [],
        ];
        foreach ($readings as $r) {
            $ts = isset($r['reading_time']) ? (int) (strtotime($r['reading_time']) * 1000) : 0;
            $series['reading_time'][] = $ts;
            foreach (array_keys($series) as $key) {
                if ($key === 'reading_time') {
                    continue;
                }
                $series[$key][] = isset($r[$key]) && $r[$key] !== null ? (float) $r[$key] : null;
            }
        }
        return $series;
    }

    private function exportCsv(string $start, string $end, Response $response): Response
    {
        $tmpFile = sys_get_temp_dir() . '/n3pp_export_' . time() . '.csv';
        $this->sensorRepo->exportCsv($start, $end, $tmpFile);
        $csvContent = file_get_contents($tmpFile);
        unlink($tmpFile);
        $response->getBody()->write($csvContent);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="n3pp_data_' . date('YmdHis') . '.csv"')
            ->withHeader('Content-Length', (string) strlen($csvContent));
    }
}
