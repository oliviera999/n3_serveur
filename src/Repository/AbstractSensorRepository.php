<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Classe abstraite pour les repositories de capteurs MSP1 et N3PP.
 * Fournit les méthodes communes de lecture (getLatest, fetchBetween, stats)
 * utilisées par AbstractSensorRealtimeDataProvider et les pages de données.
 */
abstract class AbstractSensorRepository extends AbstractRepository
{
    /**
     * Retourne le nom de la table (msp1Data, n3ppData, etc.).
     */
    abstract protected function getTableName(): string;

    /**
     * Retourne la liste des colonnes capteurs pour les lectures.
     *
     * @return string[]
     */
    abstract public function getSensorColumns(): array;

    /**
     * Dernière lecture enregistrée.
     *
     * @return array<string, mixed>|null
     */
    public function getLatest(): ?array
    {
        $table = $this->getTableName();
        $sql = "SELECT * FROM `{$table}` ORDER BY reading_time DESC LIMIT 1";
        $row = $this->fetchOne($sql);
        return $row !== null && $row !== [] ? $row : null;
    }

    /**
     * Lectures entre deux dates (incluses).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBetween(string $start, string $end): array
    {
        $table = $this->getTableName();
        $columns = ['reading_time', 'sensor', 'version'];
        foreach ($this->getSensorColumns() as $col) {
            if (!in_array($col, $columns, true)) {
                $columns[] = $col;
            }
        }
        $colsList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $sql = "SELECT {$colsList} FROM `{$table}` WHERE reading_time BETWEEN :start AND :end ORDER BY reading_time DESC";
        return $this->fetchAll($sql, [':start' => $start, ':end' => $end]);
    }

    /**
     * Date/heure de la dernière lecture.
     */
    public function getLastReadingDate(): ?string
    {
        $table = $this->getTableName();
        $sql = "SELECT MAX(reading_time) AS last_date FROM `{$table}`";
        $result = $this->fetchOne($sql);
        return $result['last_date'] ?? null;
    }

    /**
     * Nombre de lectures aujourd'hui.
     */
    public function countReadingsToday(): int
    {
        $startOfDay = date('Y-m-d 00:00:00');
        $endOfDay = date('Y-m-d 23:59:59');
        return $this->countReadingsBetween($startOfDay, $endOfDay);
    }

    /**
     * Nombre de lectures entre deux dates.
     */
    public function countReadingsBetween(string $start, string $end): int
    {
        $table = $this->getTableName();
        $sql = "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE reading_time BETWEEN :start AND :end";
        $result = $this->fetchOne($sql, [':start' => $start, ':end' => $end]);
        return (int) ($result['cnt'] ?? 0);
    }

    /**
     * Exporte les données en CSV.
     *
     * @return int Nombre de lignes écrites
     */
    public function exportCsv(string $start, string $end, string $filePath): int
    {
        $rows = $this->fetchBetween($start, $end);
        if ($rows === []) {
            return 0;
        }
        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier ' . $filePath);
        }
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        return count($rows);
    }

    /**
     * Version du firmware de la dernière mesure.
     */
    public function getFirmwareVersion(): string
    {
        $row = $this->getLatest();
        return $row['version'] ?? 'N/A';
    }
}
