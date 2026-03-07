<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\Database;
use App\Repository\SensorReadRepository;
use App\Service\SensorStatisticsService;

class AquaponieController
{
    private SensorReadRepository $sensorReadRepo;
    private SensorStatisticsService $statsService;

    public function __construct()
    {
        $pdo = Database::getConnection();
        $this->sensorReadRepo = new SensorReadRepository($pdo);
        $this->statsService   = new SensorStatisticsService($pdo);
    }

    /**
     * Affiche la page publique des données d'aquaponie
     */
    public function show(): void
    {
        // ------------------------------------------------------------
        // Période d'analyse
        // ------------------------------------------------------------
        $lastDate        = $this->sensorReadRepo->getLastReadingDate();
        $defaultEndDate  = $lastDate ?: date('Y-m-d H:i:s');
        $defaultStartDate = date('Y-m-d H:i:s', strtotime($defaultEndDate . ' -1 day'));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $startDate = $_POST['start_date'] . ' ' . ($_POST['start_time'] ?? '00:00:00');
            $endDate   = $_POST['end_date']   . ' ' . ($_POST['end_time']   ?? '23:59:59');
        } else {
            $startDate = $defaultStartDate;
            $endDate   = $defaultEndDate;
        }

        // ------------------------------------------------------------
        // Récupération des enregistrements & préparation des séries
        // ------------------------------------------------------------
        $readings      = $this->sensorReadRepo->fetchBetween($startDate, $endDate);
        $measure_count = count($readings);

        // Utilitaires internes
        $col = static fn(array $rows, string $key): array => array_column($rows, $key);
        $encode = static fn(array $values): string => json_encode(array_reverse($values), JSON_NUMERIC_CHECK);

        // Séries pour Highcharts (ordre chronologique inversé comme legacy)
        $EauAquarium  = $encode($col($readings, 'EauAquarium'));
        $EauReserve   = $encode($col($readings, 'EauReserve'));
        $EauPotager   = $encode($col($readings, 'EauPotager'));
        $TempAir      = $encode($col($readings, 'TempAir'));
        $TempEau      = $encode($col($readings, 'TempEau'));
        $Humidite     = $encode($col($readings, 'Humidite'));
        $Luminosite   = $encode($col($readings, 'Luminosite'));
        $etatPompeAqua = $encode($col($readings, 'etatPompeAqua'));
        $etatPompeTank = $encode($col($readings, 'etatPompeTank'));
        $etatHeat      = $encode($col($readings, 'etatHeat'));
        $etatUV        = $encode($col($readings, 'etatUV'));
        $bouffePetits  = $encode($col($readings, 'bouffePetits'));
        $bouffeGros    = $encode($col($readings, 'bouffeGros'));

        // Horodatages (ms epoch) +1 h comme dans le script legacy
        $reading_time_ts = array_map(static fn($ts) => (strtotime($ts . ' +1 hours')) * 1000, $col(array_reverse($readings), 'reading_time'));
        $reading_time    = json_encode($reading_time_ts, JSON_NUMERIC_CHECK);

        // ------------------------------------------------------------
        // Dernière lecture (pour jauges)
        // ------------------------------------------------------------
        $lastReading                = $this->sensorReadRepo->getLastReadings();
        $last_reading_tempair       = $lastReading['TempAir']       ?? 0;
        $last_reading_tempeau       = $lastReading['TempEau']       ?? 0;
        $last_reading_humi          = $lastReading['Humidite']      ?? 0;
        $last_reading_lumi          = $lastReading['Luminosite']    ?? 0;
        $last_reading_eauaqua       = $lastReading['EauAquarium']   ?? 0;
        $last_reading_eaureserve    = $lastReading['EauReserve']    ?? 0;
        $last_reading_eaupota       = $lastReading['EauPotager']    ?? 0;
        $last_reading_time          = $lastReading['reading_time']  ?? $defaultEndDate;

        // ------------------------------------------------------------
        // Statistiques
        // ------------------------------------------------------------
        $stats = fn(string $col) => [
            'min'    => $this->statsService->min($col, $startDate, $endDate),
            'max'    => $this->statsService->max($col, $startDate, $endDate),
            'avg'    => $this->statsService->avg($col, $startDate, $endDate),
            'stddev' => $this->statsService->stddev($col, $startDate, $endDate),
        ];

        $sTempAir    = $stats('TempAir');
        $sTempEau    = $stats('TempEau');
        $sHumidite   = $stats('Humidite');
        $sLuminosite = $stats('Luminosite');
        $sEauAquarium = $stats('EauAquarium');
        $sEauReserve  = $stats('EauReserve');
        $sEauPotager  = $stats('EauPotager');

        $min_tempair   = $sTempAir['min'];
        $max_tempair   = $sTempAir['max'];
        $avg_tempair   = $sTempAir['avg'];
        $stddev_tempair = $sTempAir['stddev'];

        $min_tempeau   = $sTempEau['min'];
        $max_tempeau   = $sTempEau['max'];
        $avg_tempeau   = $sTempEau['avg'];
        $stddev_tempeau = $sTempEau['stddev'];

        $min_humi     = $sHumidite['min'];
        $max_humi     = $sHumidite['max'];
        $avg_humi     = $sHumidite['avg'];
        $stddev_humi  = $sHumidite['stddev'];

        $min_lumi     = $sLuminosite['min'];
        $max_lumi     = $sLuminosite['max'];
        $avg_lumi     = $sLuminosite['avg'];
        $stddev_lumi  = $sLuminosite['stddev'];

        $min_eauaqua   = $sEauAquarium['min'];
        $max_eauaqua   = $sEauAquarium['max'];
        $avg_eauaqua   = $sEauAquarium['avg'];
        $stddev_eauaqua = $sEauAquarium['stddev'];

        $min_eaureserve   = $sEauReserve['min'];
        $max_eaureserve   = $sEauReserve['max'];
        $avg_eaureserve   = $sEauReserve['avg'];
        $stddev_eaureserve = $sEauReserve['stddev'];

        $min_eaupota   = $sEauPotager['min'];
        $max_eaupota   = $sEauPotager['max'];
        $avg_eaupota   = $sEauPotager['avg'];
        $stddev_eaupota = $sEauPotager['stddev'];

        // ------------------------------------------------------------
        // Périodes complémentaires pour l'affichage texte
        // ------------------------------------------------------------
        $duration_seconds = strtotime($endDate) - strtotime($startDate);
        $days    = (int) floor($duration_seconds / 86400);
        $hours   = (int) floor(($duration_seconds % 86400) / 3600);
        $minutes = (int) floor(($duration_seconds % 3600) / 60);
        $duration_str = "$days jours, $hours heures, $minutes minutes";

        // Ancienne métrique first_reading_begin (nombre total d'enregistrements)
        $pdo = Database::getConnection();
        $firstReadingRow = $pdo->query('SELECT MIN(reading_time) AS min_time FROM ffp3Data')->fetch(\PDO::FETCH_ASSOC);
        $first_reading_time_begin = $firstReadingRow['min_time'] ?? $defaultStartDate;

        $timepastbegin = round((strtotime($last_reading_time) - strtotime($first_reading_time_begin)) / 86400, 1);

        // ------------------------------------------------------------
        // Export CSV si demandé
        // ------------------------------------------------------------
        if (isset($_POST['export_csv'])) {
            // Reuse repository exportCsv
            $tmpFile = sys_get_temp_dir() . '/sensor_export_' . time() . '.csv';
            $this->sensorReadRepo->exportCsv($startDate, $endDate, $tmpFile);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="sensor_data_' . date('YmdHis') . '.csv"');
            readfile($tmpFile);
            unlink($tmpFile);
            exit;
        }

        // Legacy compatibilité : variables de nommage identiques au script initial
        $start_date = $startDate;
        $end_date   = $endDate;

        // Nombre total d'enregistrements (max id)
        $rowMaxId = $pdo->query('SELECT MAX(id) AS max_amount2 FROM ffp3Data')->fetch(\PDO::FETCH_ASSOC);
        $first_reading_begin = $rowMaxId['max_amount2'] ?? 0;

        // ------------------------------------------------------------
        // Injection dans le template (scope local)
        // ------------------------------------------------------------
        include __DIR__ . '/../../templates/ffp3-data.php';
    }
} 