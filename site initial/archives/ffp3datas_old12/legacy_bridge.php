<?php

declare(strict_types=1);

use App\Config\Database;
use App\Repository\SensorReadRepository;
use App\Service\SensorStatisticsService;

/**
 * Pont de compatibilité pour le script legacy ffp3-data.php.
 * Toutes les fonctions ci-dessous imitent l'API historique mais délèguent aux
 * nouvelles classes (Repository et Services) -> aucun identifiant n'est codé en clair.
 */

if (!function_exists('legacyRepo')) {
    function legacyPdo(): \PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }
        return $pdo;
    }

    function legacyRepo(): SensorReadRepository
    {
        static $repo = null;
        if ($repo === null) {
            $repo = new SensorReadRepository(legacyPdo());
        }
        return $repo;
    }

    function legacyStats(): SensorStatisticsService
    {
        static $stats = null;
        if ($stats === null) {
            $stats = new SensorStatisticsService(legacyPdo());
        }
        return $stats;
    }

    // ------------------------------------------------------------------
    // Fonctions attendues par ffp3-data.php
    // ------------------------------------------------------------------
    function getLastReadingDate(): ?string
    {
        return legacyRepo()->getLastReadingDate();
    }

    function getSensorData(string $start, string $end): array
    {
        return legacyRepo()->fetchBetween($start, $end);
    }

    function exportSensorData(string $start, string $end): void
    {
        $tmp = sys_get_temp_dir() . '/sensor_export_' . time() . '.csv';
        legacyRepo()->exportCsv($start, $end, $tmp);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=données_capteurs.csv');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /**
     * Compatibilité : l'ancien script appelle getLastReadings($start, $end) alors
     * que seule la limite était réellement utilisée. On accepte un nombre
     * variable d'arguments.
     */
    function getLastReadings(mixed ...$args): array
    {
        // Si le premier paramètre est un int, on suppose que c'est la limite.
        // Sinon, on récupère 1 seule ligne (comportement historique).
        $limit = (isset($args[0]) && is_int($args[0])) ? $args[0] : 1;
        return legacyRepo()->getLastReadings($limit);
    }

    function getAllReadings(): array|false
    {
        $stmt = legacyPdo()->query('SELECT MAX(id) AS max_amount2 FROM ffp3Data');
        return $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
    }

    function getFirstReadings(int $limit): array|false
    {
        $sql = 'SELECT reading_time AS min_amount2 FROM (SELECT reading_time FROM ffp3Data ORDER BY reading_time DESC LIMIT :limit) as t ORDER BY reading_time ASC LIMIT 1';
        $stmt = legacyPdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: false;
    }

    function getFirstReadingsBegin(): array|false
    {
        $stmt = legacyPdo()->query('SELECT MIN(reading_time) AS min_amount3 FROM ffp3Data');
        return $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
    }

    // Statistiques -------------------------------------------------------
    function minReading(string $start, string $end, string $column): ?float
    {
        return legacyStats()->min($column, $start, $end);
    }
    function maxReading(string $start, string $end, string $column): ?float
    {
        return legacyStats()->max($column, $start, $end);
    }
    function avgReading(string $start, string $end, string $column): ?float
    {
        return legacyStats()->avg($column, $start, $end);
    }
    function stddevReading(string $start, string $end, string $column): ?float
    {
        return legacyStats()->stddev($column, $start, $end);
    }
} 