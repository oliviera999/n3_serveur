<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Repository\SensorReadRepository;
use App\Service\CsvExportService;
use App\Service\DateRangeExtractor;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Export CSV des données capteurs FFP3.
 * Utilise CsvExportService pour unifier avec MSP1/N3PP.
 */
class ExportController
{
    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private CsvExportService $csvExportService,
        private DateRangeExtractor $dateRangeExtractor,
    ) {
    }

    /**
     * Point d'entrée HTTP : /export-data?start=YYYY-MM-DD[+HH:ii:ss]&end=YYYY-MM-DD[+HH:ii:ss]
     */
    public function downloadCsv(Request $request, Response $response): Response
    {
        try {
            [$startDate, $endDate] = $this->dateRangeExtractor->extractFromQuery(
                $request,
                date('Y-m-d H:i:s', strtotime('-1 day')),
                date('Y-m-d H:i:s')
            );
        } catch (\RuntimeException $e) {
            return ResponseHelper::text($response, 'Paramètres de date invalides', 400);
        }

        try {
            return $this->csvExportService->export(
                $this->sensorReadRepo,
                $startDate,
                $endDate,
                $response,
                'sensor-data',
                'Aucune donnée pour la période demandée'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::text($response, 'Erreur serveur', 500);
        }
    }
}
