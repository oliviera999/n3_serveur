<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LogService;
use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\SensorReadRepository;
use App\Security\CsrfService;
use App\Service\ChartDataService;
use App\Service\StatisticsAggregatorService;
use App\Service\TemplateRenderer;
use App\Service\WaterBalanceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AquaponieController
{
    private SensorReadRepository $sensorReadRepo;
    private StatisticsAggregatorService $statsAggregator;
    private ChartDataService $chartDataService;
    private WaterBalanceService $waterBalanceService;
    private TemplateRenderer $renderer;
    private LogService $logger;
    private CsrfService $csrfService;

    public function __construct(
        SensorReadRepository $sensorReadRepo,
        StatisticsAggregatorService $statsAggregator,
        ChartDataService $chartDataService,
        WaterBalanceService $waterBalanceService,
        TemplateRenderer $renderer,
        LogService $logger,
        CsrfService $csrfService
    ) {
        $this->sensorReadRepo = $sensorReadRepo;
        $this->statsAggregator = $statsAggregator;
        $this->chartDataService = $chartDataService;
        $this->waterBalanceService = $waterBalanceService;
        $this->renderer = $renderer;
        $this->logger = $logger;
        $this->csrfService = $csrfService;
    }

    /**
     * Affiche la page suivi « vue classique » (servie à /aquaponie-alt).
     */
    public function show(Request $request, Response $response): Response
    {
        try {
            $data = $this->getAquaponieData($request, $response);
            if ($data instanceof Response) {
                return $data;
            }
            $html = $this->renderer->render('aquaponie.twig', $data);
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'CSRF') !== false) {
                $response->getBody()->write('Token CSRF invalide. Veuillez recharger la page et réessayer.');
                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('AquaponieController::show failure', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $response->getBody()->write("ERREUR AquaponieController: " . $e->getMessage());
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }

    /**
     * Affiche la page suivi principale « vue paysage » (servie à /aquaponie).
     */
    public function showAlt(Request $request, Response $response): Response
    {
        try {
            $data = $this->getAquaponieData($request, $response);
            if ($data instanceof Response) {
                return $data;
            }
            $html = $this->renderer->render('aquaponie_alt.twig', $data);
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'CSRF') !== false) {
                $response->getBody()->write('Token CSRF invalide. Veuillez recharger la page et réessayer.');
                return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('AquaponieController::showAlt failure', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $response->getBody()->write("ERREUR AquaponieController: " . $e->getMessage());
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }

    /**
     * Construit les données communes pour les pages suivi (vue classique et vue paysage).
     * Retourne un tableau de données pour le template, ou une Response (redirection / export CSV) à retourner par l'appelant.
     */
    private function getAquaponieData(Request $request, Response $response): array|Response
    {
        $lastDate = $this->sensorReadRepo->getLastReadingDate();
        $defaultEndDate = $lastDate ?: date('Y-m-d H:i:s');
        $defaultStartDate = date('Y-m-d H:i:s', strtotime($defaultEndDate . ' -6 hours'));

        [$startDate, $endDate] = $this->extractDateRange($request, $defaultStartDate, $defaultEndDate);

        $readings = $this->sensorReadRepo->fetchBetween($startDate, $endDate);
        $measure_count = count($readings);

        $chartSeries = $this->chartDataService->prepareSeriesData($readings);
        $reading_time = $this->chartDataService->prepareTimestamps($readings);

        $lastReading = $this->sensorReadRepo->getLastReadings();
        $lastReadingExtracted = $this->chartDataService->extractLastReadings($lastReading);

        $allStats = $this->statsAggregator->aggregateAllStats($startDate, $endDate);
        $statsFlattened = $this->statsAggregator->flattenForLegacy($allStats);

        $duration = $this->calculateDuration($startDate, $endDate);

        $body = $request->getParsedBody() ?? [];
        if (isset($body['export_csv'])) {
            return $this->exportCsv($startDate, $endDate, $response);
        }

        $queryParams = $request->getQueryParams();
        if (isset($queryParams['legacy'])) {
            return $response->withStatus(302)->withHeader('Location', '/aquaponie');
        }

        $firmwareVersion = $this->sensorReadRepo->getFirmwareVersion();
        $waterBalance = $this->waterBalanceService->computeBalance($startDate, $endDate);
        $environment = TableConfig::getEnvironment();

        return array_merge([
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'reading_time' => $reading_time,
            'measure_count' => $measure_count,
            'duration_str' => $duration,
            'version' => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment' => $environment,
        ], $chartSeries, [
            'last_reading_tempair' => $lastReadingExtracted['tempair'],
            'last_reading_tempeau' => $lastReadingExtracted['tempeau'],
            'last_reading_humi' => $lastReadingExtracted['humi'],
            'last_reading_lumi' => $lastReadingExtracted['lumi'],
            'last_reading_eauaqua' => $lastReadingExtracted['eauaqua'],
            'last_reading_eaureserve' => $lastReadingExtracted['eaureserve'],
            'last_reading_eaupota' => $lastReadingExtracted['eaupota'],
        ], $statsFlattened, $waterBalance);
    }

    /**
     * Affiche la page Caractéristiques du module FFP3 (description, capteurs, actionneurs, firmware, serveurs).
     */
    public function showDescription(Request $request, Response $response): Response
    {
        $html = $this->renderer->render('aquaponie_description.twig', [
            'page_title' => 'Caractéristiques du module FFP3 - n3 iot datas',
            'images_base' => '/ffp3/assets/images/aquaponie-description',
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Extrait la plage de dates depuis les paramètres POST
     * 
     * @throws \RuntimeException Si le token CSRF est invalide
     */
    private function extractDateRange(Request $request, string $defaultStart, string $defaultEnd): array
    {
        if ($request->getMethod() !== 'POST') {
            return [$defaultStart, $defaultEnd];
        }

        $body = $request->getParsedBody() ?? [];

        // Validation CSRF
        $submittedToken = $body['_csrf_token'] ?? null;
        if (!$this->csrfService->validateToken($submittedToken)) {
            throw new \RuntimeException('Token CSRF invalide');
        }

        // Nouveau format : datetime-local
        $startDatetimePost = $body['start_datetime'] ?? null;
        $endDatetimePost = $body['end_datetime'] ?? null;
        
        if ($startDatetimePost && $endDatetimePost) {
            return [
                str_replace('T', ' ', $startDatetimePost) . ':00',
                str_replace('T', ' ', $endDatetimePost) . ':00',
            ];
        }

        // Ancien format : date + time séparés
        $startDatePost = $body['start_date'] ?? null;
        $endDatePost = $body['end_date'] ?? null;
        $startTimePost = $body['start_time'] ?? null;
        $endTimePost = $body['end_time'] ?? null;
        
        if ($startDatePost && $endDatePost) {
            return [
                $startDatePost . ' ' . ($startTimePost ?: '00:00:00'),
                $endDatePost . ' ' . ($endTimePost ?: '23:59:59'),
            ];
        }

        return [$defaultStart, $defaultEnd];
    }

    /**
     * Calcule la durée lisible entre deux dates
     */
    private function calculateDuration(string $start, string $end): string
    {
        $duration_seconds = strtotime($end) - strtotime($start);
        $days = (int) floor($duration_seconds / 86400);
        $hours = (int) floor(($duration_seconds % 86400) / 3600);
        $minutes = (int) floor(($duration_seconds % 3600) / 60);
        $seconds = (int) ($duration_seconds % 60);
        
        return "$days jours, $hours heures, $minutes minutes, $seconds secondes";
    }

    /**
     * Gère l'export CSV
     */
    private function exportCsv(string $start, string $end, Response $response): Response
    {
        $tmpFile = sys_get_temp_dir() . '/sensor_export_' . time() . '.csv';
        $this->sensorReadRepo->exportCsv($start, $end, $tmpFile);
        
        $csvContent = file_get_contents($tmpFile);
        unlink($tmpFile);
        
        $response->getBody()->write($csvContent);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="sensor_data_' . date('YmdHis') . '.csv"')
            ->withHeader('Content-Length', (string) strlen($csvContent));
    }
}
