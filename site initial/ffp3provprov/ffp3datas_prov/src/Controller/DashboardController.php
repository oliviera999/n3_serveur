<?php

namespace App\Controller;

use App\Config\Database;
use App\Repository\SensorReadRepository;
use App\Service\SensorStatisticsService;
use App\Service\TemplateRenderer;

class DashboardController
{
    private SensorReadRepository $sensorReadRepo;
    private SensorStatisticsService $statsService;

    public function __construct()
    {
        $pdo = Database::getConnection();
        $this->sensorReadRepo = new SensorReadRepository($pdo);
        $this->statsService = new SensorStatisticsService($pdo);
    }

    /**
     * Affiche le tableau de bord avec les données des capteurs
     */
    public function show(): void
    {
        // Récupérer la dernière date de lecture
        $lastDate = $this->sensorReadRepo->getLastReadingDate();
        $defaultEndDate = $lastDate ?: date('Y-m-d H:i:s');
        $defaultStartDate = date('Y-m-d H:i:s', strtotime($defaultEndDate . ' -1 day'));
        
        // Traitement du formulaire de filtrage
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $startDate = $_POST['start_date'] . ' ' . ($_POST['start_time'] ?? '00:00:00');
            $endDate = $_POST['end_date'] . ' ' . ($_POST['end_time'] ?? '23:59:59');
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
        
        // Gestion de l'export CSV
        if (isset($_POST['export_csv'])) {
            $this->exportCsv($startDate, $endDate);
        }

        // Sélection du template : legacy ou Twig
        $useLegacy = isset($_GET['legacy']);
        if ($useLegacy) {
            $startDate = $startDate; $endDate = $endDate; // alias pour template PHP
            $readingsCount = $readingsCount;
            include __DIR__ . '/../../templates/dashboard.php';
        } else {
            echo TemplateRenderer::render('dashboard.twig', [
                'startDate'     => $startDate,
                'endDate'       => $endDate,
                'duration'      => $duration,
                'readingsCount' => $readingsCount,
                'lastReading'   => $lastReading,
                'stats'         => $stats,
            ]);
        }
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
    private function exportCsv(string $start, string $end): void
    {
        $filename = 'sensor_data_' . date('YmdHis') . '.csv';
        $filePath = sys_get_temp_dir() . '/' . $filename;
        
        $this->sensorReadRepo->exportCsv($start, $end, $filePath);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        
        readfile($filePath);
        unlink($filePath);
        exit;
    }
} 