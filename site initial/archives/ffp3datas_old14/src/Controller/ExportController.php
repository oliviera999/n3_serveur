<?php

namespace App\Controller;

use App\Config\Database;
use App\Repository\SensorReadRepository;
use DateTimeImmutable;
use Throwable;

class ExportController
{
    /**
     * Point d'entrée HTTP : /export-data?start=YYYY-MM-DD[+HH:ii:ss]&end=YYYY-MM-DD[+HH:ii:ss]
     * Valide les paramètres, produit un CSV en streaming puis termine le script.
     */
    public function downloadCsv(): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        // Récupération des paramètres GET
        $startParam = $_GET['start'] ?? null;
        $endParam   = $_GET['end']   ?? null;

        try {
            $start = $startParam ? new DateTimeImmutable($startParam) : new DateTimeImmutable('-1 day');
            $end   = $endParam   ? new DateTimeImmutable($endParam)   : new DateTimeImmutable();
        } catch (\Exception $e) {
            http_response_code(400);
            echo 'Paramètres de date invalides';
            return;
        }

        try {
            $pdo  = Database::getConnection();
            $repo = new SensorReadRepository($pdo);

            // Fichier temporaire pour éviter de charger tout en mémoire
            $tmpFile = tempnam(sys_get_temp_dir(), 'export_');
            $nbLines = $repo->exportCsv($start, $end, $tmpFile);

            if ($nbLines === 0) {
                http_response_code(204); // No content
                echo 'Aucune donnée pour la période demandée';
                @unlink($tmpFile);
                return;
            }

            // Envoi des en-têtes pour le téléchargement
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="sensor-data.csv"');
            header('Content-Length: ' . filesize($tmpFile));

            readfile($tmpFile);
            @unlink($tmpFile);
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Erreur serveur';
        }
    }
} 