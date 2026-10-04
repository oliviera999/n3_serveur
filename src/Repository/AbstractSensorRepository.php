<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Classe abstraite pour les repositories de capteurs MSP1, N3PP et ENERGIE.
 * Fournit les méthodes communes : getLatest, fetchBetween, exportCsv, getLastReadingDate, countReadingsToday.
 * Les sous-classes définissent getTableName() et getSensorColumns().
 */
abstract class AbstractSensorRepository extends AbstractRepository
{
    abstract protected function getTableName(): string;

    /** @return string[] */
    abstract public function getSensorColumns(): array;

    /**
     * Récupère la version du firmware de la dernière mesure enregistrée.
     */
    public function getFirmwareVersion(): string
    {
        $table = $this->getTableName();
        $sql = "SELECT version FROM `{$table}` ORDER BY reading_time DESC LIMIT 1";
        $result = $this->fetchOne($sql);
        return $result['version'] ?? 'N/A';
    }

    /**
     * Retourne la dernière mesure enregistrée.
     *
     * @return array<string, mixed>|null
     */
    public function getLatest(): ?array
    {
        $table = $this->getTableName();
        $sql = "SELECT * FROM `{$table}` ORDER BY reading_time DESC LIMIT 1";
        $row = $this->fetchOne($sql);
        return $row;
    }

    /**
     * Clause SQL optionnelle pour exclure le bruit qualitatif des graphiques/stats (P0-U06).
     * Les données restent en BDD ; seul l'affichage est filtré.
     */
    protected function qualityFilterSql(): string
    {
        return '';
    }

    /**
     * Retourne les mesures entre deux dates (incluses).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBetween(string $start, string $end): array
    {
        $table = $this->getTableName();
        $filter = $this->qualityFilterSql();
        $whereFilter = $filter !== '' ? " AND ({$filter})" : '';
        $sql = "SELECT * FROM `{$table}` WHERE reading_time BETWEEN :start AND :end{$whereFilter} ORDER BY reading_time ASC";
        return $this->fetchAll($sql, [':start' => $start, ':end' => $end]);
    }

    /**
     * Exporte les mesures d'une plage dans un fichier CSV et retourne le nombre de lignes
     * de données écrites (contrat attendu par {@see \App\Service\CsvExportService::export()}).
     *
     * Commun à toutes les familles « capteurs » (MSP1, N3PP, ENERGIE) : avant 6.40.1, seul
     * ENERGIE l'implémentait et l'export CSV de /meteo et /serre levait une Error (méthode
     * inexistante) → HTTP 500. Colonnes = id, sensor, version, getSensorColumns(), reading_time.
     * Le filtre qualité ({@see qualityFilterSql()}) est appliqué comme dans {@see fetchBetween()} :
     * le CSV contient exactement les mesures affichées par la page. L'en-tête est toujours écrit,
     * même sans donnée (CSV vide valide). Lecture en streaming (pas de fetchAll).
     */
    public function exportCsv(string $start, string $end, string $filePath): int
    {
        $columns = ['id', 'sensor', 'version', ...$this->getSensorColumns(), 'reading_time'];
        $columnList = implode(', ', $columns);
        $filter = $this->qualityFilterSql();
        $whereFilter = $filter !== '' ? " AND ({$filter})" : '';
        $sql = "SELECT {$columnList} FROM `{$this->getTableName()}`"
            . " WHERE reading_time BETWEEN :start AND :end{$whereFilter} ORDER BY reading_time ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':start' => $start, ':end' => $end]);

        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier ' . $filePath);
        }

        fputcsv($handle, $columns, ',', '"', '\\');

        $count = 0;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            fputcsv($handle, $row, ',', '"', '\\');
            $count++;
        }

        fclose($handle);

        return $count;
    }

    /**
     * Retourne la date/heure de la dernière mesure, ou null si aucune donnée.
     */
    public function getLastReadingDate(): ?string
    {
        $table = $this->getTableName();
        $sql = "SELECT MAX(reading_time) AS last_date FROM `{$table}`";
        $result = $this->fetchOne($sql);
        return isset($result['last_date']) ? (string) $result['last_date'] : null;
    }

    /**
     * Retourne la date/heure de la première mesure (début du fonctionnement du module), ou null si aucune donnée.
     */
    public function getFirstReadingDate(): ?string
    {
        $table = $this->getTableName();
        $sql = "SELECT MIN(reading_time) AS first_date FROM `{$table}`";
        $result = $this->fetchOne($sql);
        return isset($result['first_date']) ? (string) $result['first_date'] : null;
    }

    /**
     * Compte le nombre de mesures reçues aujourd'hui.
     */
    public function countReadingsToday(): int
    {
        $table = $this->getTableName();
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 23:59:59');
        $sql = "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE reading_time BETWEEN :start AND :end";
        $result = $this->fetchOne($sql, [':start' => $start, ':end' => $end]);
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Compte les mesures entre deux dates (incluses) en appliquant le même filtre
     * qualité que {@see fetchBetween()}. Équivalent COUNT(*) : évite de rapatrier
     * toutes les lignes en mémoire pour un simple comptage (ex. calcul d'uptime
     * pollé toutes les 15 s par la grille de supervision).
     */
    public function countReadingsBetween(string $start, string $end): int
    {
        $table = $this->getTableName();
        $filter = $this->qualityFilterSql();
        $whereFilter = $filter !== '' ? " AND ({$filter})" : '';
        $sql = "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE reading_time BETWEEN :start AND :end{$whereFilter}";
        $result = $this->fetchOne($sql, [':start' => $start, ':end' => $end]);
        return (int) ($result['cnt'] ?? 0);
    }
}
