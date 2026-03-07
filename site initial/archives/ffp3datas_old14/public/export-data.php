<?php

// Point d'entrée pour l'export des données capteurs au format CSV
// Ce script permet de télécharger les données sur une période donnée (GET ?start=...&end=...)
// Il loggue l'opération et gère les erreurs de paramétrage

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Repository\SensorReadRepository;
use DateTimeImmutable;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

// --------------------------------------------------------------
// Initialisation du logger
// --------------------------------------------------------------
$logPath = __DIR__ . '/../var/logs';
if (!is_dir($logPath)) {
    mkdir($logPath, 0775, true);
}
$logger = new Logger('export-data');
$logger->pushHandler(new StreamHandler($logPath . '/export-data.log', Logger::INFO));

header('Content-Type: text/plain; charset=utf-8');

// --------------------------------------------------------------
// Récupération et validation des paramètres d'URL (start, end)
// --------------------------------------------------------------
$startParam = $_GET['start'] ?? null; // format YYYY-MM-DD HH:ii:ss ou YYYY-MM-DD
$endParam   = $_GET['end']   ?? null;

try {
    $start = $startParam ? new DateTimeImmutable($startParam) : (new DateTimeImmutable('-1 day'));
    $end   = $endParam   ? new DateTimeImmutable($endParam)   : new DateTimeImmutable();
} catch (Exception $e) {
    http_response_code(400);
    echo 'Paramètres de date invalides';
    exit;
}

try {
    $pdo       = Database::getConnection();
    $repo      = new SensorReadRepository($pdo);
    $tmpFile   = tempnam(sys_get_temp_dir(), 'export_');
    $nbLines   = $repo->exportCsv($start, $end, $tmpFile);

    if ($nbLines === 0) {
        http_response_code(204); // No content
        echo 'Aucune donnée pour la période demandée';
        unlink($tmpFile);
        exit;
    }

    // Envoi des headers pour forcer le téléchargement du CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sensor-data.csv"');
    header('Content-Length: ' . filesize($tmpFile));

    readfile($tmpFile);
    unlink($tmpFile);

    $logger->info('Export CSV', ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s'), 'lines' => $nbLines]);
} catch (Throwable $e) {
    $logger->error('Erreur export', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo 'Erreur serveur';
} 