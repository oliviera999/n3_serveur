<?php

namespace App\Controller;

use App\Config\Database;
use App\Repository\SensorReadRepository;
use App\Service\SensorStatisticsService;

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
        
        // ---------------------------------------------------------------------
        // Préparation des données attendues par le template (compatibilité legacy)
        // ---------------------------------------------------------------------
        $start_date = $startDate;   // noms attendus par l'ancien template
        $end_date   = $endDate;

        // Mesures chronologiques en millisecondes (Highcharts)
        $reading_time_raw = array_reverse(array_column($readings, 'reading_time'));
        $reading_time_ms  = array_map(fn(string $dt) => strtotime($dt) * 1000, $reading_time_raw);
        $reading_time     = json_encode($reading_time_ms, JSON_NUMERIC_CHECK);

        // Séries numériques
        $columns = [
            'EauAquarium','EauReserve','EauPotager',
            'TempAir','TempEau','Humidite','Luminosite',
            'etatPompeAqua','etatPompeTank','etatHeat','etatUV',
            'bouffePetits','bouffeGros',
        ];
        foreach ($columns as $col) {
            $$col = json_encode(array_reverse(array_column($readings, $col)), JSON_NUMERIC_CHECK);
        }

        // Compteurs & informations diverses
        $measure_count           = $readingsCount;
        $first_reading_time_begin = $readingsCount > 0 ? date('d/m/Y H:i:s', strtotime($readings[$readingsCount - 1]['reading_time'])) : '';
        $timepastbegin           = $readingsCount > 0 ? round((strtotime($lastDate) - strtotime($readings[$readingsCount - 1]['reading_time'])) / (3600 * 24), 1) : 0;
        $first_reading_begin     = $readingsCount > 0 ? ($readings[$readingsCount - 1]['id'] ?? 0) : 0;

        // Mappage des statistiques détaillées pour la vue
        $avg_tempeau   = $stats['TempEau']['avg'];  $min_tempeau   = $stats['TempEau']['min'];  $max_tempeau   = $stats['TempEau']['max'];  $stddev_tempeau   = $stats['TempEau']['stddev'];
        $avg_tempair   = $stats['TempAir']['avg'];  $min_tempair   = $stats['TempAir']['min'];  $max_tempair   = $stats['TempAir']['max'];  $stddev_tempair   = $stats['TempAir']['stddev'];
        $avg_humi      = $stats['Humidite']['avg']; $min_humi      = $stats['Humidite']['min']; $max_humi      = $stats['Humidite']['max']; $stddev_humi      = $stats['Humidite']['stddev'];
        $avg_lumi      = $stats['Luminosite']['avg'];$min_lumi      = $stats['Luminosite']['min'];$max_lumi      = $stats['Luminosite']['max'];$stddev_lumi      = $stats['Luminosite']['stddev'];
        $avg_eauaqua   = $stats['EauAquarium']['avg'];$min_eauaqua   = $stats['EauAquarium']['min'];$max_eauaqua   = $stats['EauAquarium']['max'];$stddev_eauaqua   = $stats['EauAquarium']['stddev'];
        $avg_eaureserve= $stats['EauReserve']['avg'];$min_eaureserve= $stats['EauReserve']['min'];$max_eaureserve= $stats['EauReserve']['max'];$stddev_eaureserve= $stats['EauReserve']['stddev'];
        $avg_eaupota   = $stats['EauPotager']['avg'];$min_eaupota   = $stats['EauPotager']['min'];$max_eaupota   = $stats['EauPotager']['max'];$stddev_eaupota   = $stats['EauPotager']['stddev'];

        // ---------------------------------------------------------------------
        // Affichage
        // ---------------------------------------------------------------------
        include __DIR__ . '/../../templates/dashboard.php';
        return;
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