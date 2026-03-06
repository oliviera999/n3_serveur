<?php

namespace App\Controller;

use App\Config\TableConfig;
use App\Config\Version;
use App\Repository\SensorReadRepository;
use App\Service\SensorStatisticsService;
use App\Service\TemplateRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
{
    public function __construct(
        private SensorReadRepository $sensorReadRepo,
        private SensorStatisticsService $statsService,
        private TemplateRenderer $renderer
    ) {
    }

    /**
     * Affiche le tableau de bord avec les données des capteurs
     */
    public function show(Request $request, Response $response): Response
    {
        // Récupérer la dernière date de lecture
        $lastDate = $this->sensorReadRepo->getLastReadingDate();
        $defaultEndDate = $lastDate ?: date('Y-m-d H:i:s');
        $defaultStartDate = date('Y-m-d H:i:s', strtotime($defaultEndDate . ' -1 day'));
        
        // Traitement du formulaire de filtrage
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody() ?? [];
            $startDate = ($body['start_date'] ?? '') . ' ' . ($body['start_time'] ?? '00:00:00');
            $endDate = ($body['end_date'] ?? '') . ' ' . ($body['end_time'] ?? '23:59:59');
            
            // Gestion de l'export CSV
            if (isset($body['export_csv'])) {
                return $this->exportCsv($startDate, $endDate, $response);
            }
        } else {
            $startDate = $defaultStartDate;
            $endDate = $defaultEndDate;
        }
        
        // Récupérer les données pour la période
        $readings = $this->sensorReadRepo->fetchBetween($startDate, $endDate);
        $readingsCount = count($readings);
        
        // Extraire la dernière lecture
        $lastReading = $readings[0] ?? null;
        
        // Calculer les statistiques
        $stats = [
            'TempAir' => $this->calculateStats('TempAir', $startDate, $endDate),
            'TempEau' => $this->calculateStats('TempEau', $startDate, $endDate),
            'Humidite' => $this->calculateStats('Humidite', $startDate, $endDate),
            'Luminosite' => $this->calculateStats('Luminosite', $startDate, $endDate),
            'EauAquarium' => $this->calculateStats('EauAquarium', $startDate, $endDate),
            'EauReserve' => $this->calculateStats('EauReserve', $startDate, $endDate),
            'EauPotager' => $this->calculateStats('EauPotager', $startDate, $endDate),
        ];
        
        // Calculer la durée de la période
        $duration = $this->calculateDuration($startDate, $endDate);

        // Récupérer la version du firmware ESP32
        $firmwareVersion = $this->sensorReadRepo->getFirmwareVersion();

        // Environnement actuel
        $environment = TableConfig::getEnvironment();

        // Sélection du template : legacy ou Twig
        $html = $this->renderer->render('dashboard.twig', [
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'duration'      => $duration,
            'readingsCount' => $readingsCount,
            'lastReading'   => $lastReading,
            'stats'         => $stats,
            'version'       => Version::getWithPrefix(),
            'firmware_version' => $firmwareVersion,
            'environment'   => $environment,
        ]);
        
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
    
    /**
     * Calcule les statistiques pour une colonne donnée
     */
    private function calculateStats(string $column, string $start, string $end): array
    {
        return [
            'min' => $this->statsService->min($column, $start, $end),
            'max' => $this->statsService->max($column, $start, $end),
            'avg' => $this->statsService->avg($column, $start, $end),
            'stddev' => $this->statsService->stddev($column, $start, $end),
        ];
    }
    
    /**
     * Calcule la durée entre deux dates
     */
    private function calculateDuration(string $start, string $end): string
    {
        $startTimestamp = strtotime($start);
        $endTimestamp = strtotime($end);
        $durationSeconds = $endTimestamp - $startTimestamp;
        
        $days = floor($durationSeconds / (60 * 60 * 24));
        $hours = floor(($durationSeconds % (60 * 60 * 24)) / (60 * 60));
        $minutes = floor(($durationSeconds % (60 * 60)) / 60);
        
        return "$days jours, $hours heures, $minutes minutes";
    }
    
    /**
     * Exporte les données en CSV
     */
    private function exportCsv(string $start, string $end, Response $response): Response
    {
        $filename = 'sensor_data_' . date('YmdHis') . '.csv';
        $filePath = sys_get_temp_dir() . '/' . $filename;
        
        $this->sensorReadRepo->exportCsv($start, $end, $filePath);
        
        $csvContent = file_get_contents($filePath);
        unlink($filePath);
        
        $response->getBody()->write($csvContent);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($csvContent));
    }
} 