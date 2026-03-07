<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\Database;
use App\Repository\SensorReadRepository;
use App\Service\SensorStatisticsService;
use App\Service\TemplateRenderer;

class AquaponieController
{
    private SensorReadRepository $sensorReadRepo;
    private SensorStatisticsService $statsService;

    public function __construct()
    {
        // Force le fuseau horaire applicatif (configurable via .env)
        date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Paris');

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

        // Récupération sécurisée des paramètres POST (filtrés) -----------------
        $startDatePost = filter_input(INPUT_POST, 'start_date');
        $endDatePost   = filter_input(INPUT_POST, 'end_date');
        $startTimePost = filter_input(INPUT_POST, 'start_time');
        $endTimePost   = filter_input(INPUT_POST, 'end_time');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $startDatePost && $endDatePost) {
            // Si l'utilisateur a fourni des dates valides, on construit l'intervalle
            $startDate = $startDatePost . ' ' . ($startTimePost ?: '00:00:00');
            $endDate   = $endDatePost   . ' ' . ($endTimePost   ?: '23:59:59');
        } else {
            // Fallback : période d'un jour glissant
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

        // Horodatages (ms epoch) sans décalage supplémentaire (UTC server / Europe/Paris handled by browser)
        // Conversion en timestamp UTC (ms) en tenant compte du fuseau Europe/Paris
        $reading_time_ts = array_map(static function ($ts) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ts, new \DateTimeZone('Europe/Paris'));
            return $dt !== false ? $dt->getTimestamp() * 1000 : null;
        }, $col(array_reverse($readings), 'reading_time'));
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
        if (isset($_GET['legacy'])) {
            include __DIR__ . '/../../templates/ffp3-data.php';
        } else {
            echo TemplateRenderer::render('aquaponie.twig', [
                'start_date' => $start_date,
                'end_date'   => $end_date,
                // On passe toutes les variables utilisées dans le template Twig ;
                // pour l'instant, on injecte simplement les mêmes noms que legacy.
                'last_reading_tempair' => $last_reading_tempair,
                'last_reading_tempeau' => $last_reading_tempeau,
                'last_reading_humi'    => $last_reading_humi,
                'last_reading_lumi'    => $last_reading_lumi,
                'last_reading_eauaqua' => $last_reading_eauaqua,
                'last_reading_eaureserve' => $last_reading_eaureserve,
                'last_reading_eaupota' => $last_reading_eaupota,
                'reading_time' => $reading_time,
                'EauAquarium'=> $EauAquarium,
                'EauReserve'=> $EauReserve,
                'EauPotager'=> $EauPotager,
                'TempAir'=> $TempAir,
                'TempEau'=> $TempEau,
                'Humidite'=> $Humidite,
                'Luminosite'=> $Luminosite,
                'etatPompeAqua'=> $etatPompeAqua,
                'etatPompeTank'=> $etatPompeTank,
                'etatHeat'=> $etatHeat,
                'etatUV'=> $etatUV,
                'bouffePetits'=> $bouffePetits,
                'bouffeGros'=> $bouffeGros,
                // statistiques
                'min_tempair'=> $min_tempair,
                'max_tempair'=> $max_tempair,
                'avg_tempair'=> $avg_tempair,
                'stddev_tempair'=> $stddev_tempair,
                // statistiques complémentaires
                'min_tempeau'=> $min_tempeau,
                'max_tempeau'=> $max_tempeau,
                'avg_tempeau'=> $avg_tempeau,
                'stddev_tempeau'=> $stddev_tempeau,

                'min_humi'=> $min_humi,
                'max_humi'=> $max_humi,
                'avg_humi'=> $avg_humi,
                'stddev_humi'=> $stddev_humi,

                'min_lumi'=> $min_lumi,
                'max_lumi'=> $max_lumi,
                'avg_lumi'=> $avg_lumi,
                'stddev_lumi'=> $stddev_lumi,

                'min_eauaqua'=> $min_eauaqua,
                'max_eauaqua'=> $max_eauaqua,
                'avg_eauaqua'=> $avg_eauaqua,
                'stddev_eauaqua'=> $stddev_eauaqua,

                'min_eaureserve'=> $min_eaureserve,
                'max_eaureserve'=> $max_eaureserve,
                'avg_eaureserve'=> $avg_eaureserve,
                'stddev_eaureserve'=> $stddev_eaureserve,

                'min_eaupota'=> $min_eaupota,
                'max_eaupota'=> $max_eaupota,
                'avg_eaupota'=> $avg_eaupota,
                'stddev_eaupota'=> $stddev_eaupota,

                // métriques supplémentaires
                'duration_str'=> $duration_str,
                'measure_count'=> $measure_count,
                'first_reading_begin'=> $first_reading_begin,
                'timepastbegin'=> $timepastbegin,
                'first_reading_time_begin'=> $first_reading_time_begin,
                // ... on peut passer le reste si besoin
            ]);
        }
    }
} 