<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\Database;
use App\Repository\SensorReadRepository;
use App\Service\TemplateRenderer;
use App\Service\TideAnalysisService;

class TideStatsController
{
    private TideAnalysisService $tideService;

    public function __construct()
    {
        $pdo = Database::getConnection();
        $readRepo = new SensorReadRepository($pdo);
        $this->tideService = new TideAnalysisService($readRepo);
    }

    public function show(): void
    {
        // Période : même logique que page aquaponie (24h par défaut)
        $endDefault = date('Y-m-d H:i:s');
        $startDefault = date('Y-m-d H:i:s', strtotime('-1 day', strtotime($endDefault)));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $startDate = $_POST['start_date'] . ' ' . ($_POST['start_time'] ?? '00:00:00');
            $endDate   = $_POST['end_date']   . ' ' . ($_POST['end_time']   ?? '23:59:59');
        } else {
            $startDate = $startDefault;
            $endDate   = $endDefault;
        }

        $stats = $this->tideService->compute($startDate, $endDate);

        // Statistiques hebdomadaires sur 6 mois (≈ 26 semaines)
        $sixMonthsAgo = date('Y-m-d H:i:s', strtotime('-6 months', strtotime($endDate)));
        $weeklyStats  = $this->tideService->computeWeeklySeries($sixMonthsAgo, $endDate);

        $weeklyStatsJson = json_encode($weeklyStats, JSON_THROW_ON_ERROR);

        echo TemplateRenderer::render('tide_stats.twig', [
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'marnage_moyen'    => $stats['marnage_moyen'],
            'frequence_marees' => $stats['frequence_marees'],
            'cycles'           => $stats['cycles'],
            'reserve_pos'      => $stats['reserve_pos'],
            'reserve_neg'      => $stats['reserve_neg'],
            'reserve_var'      => $stats['reserve_var'],

            // Nouveaux graphiques
            'weekly_stats_json' => $weeklyStatsJson,
        ]);
    }
} 