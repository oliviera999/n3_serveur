<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Classe abstraite pour les repositories de capteurs (MSP1, N3PP).
 * Factorise les requêtes de lecture communes. Chaque sous-classe fournit :
 *   - getTableName()     : nom de la table dynamique via TableConfig
 *   - getSensorColumns() : colonnes autorisées pour getColumnStats()
 *   - insert($data)      : insertion spécifique (colonnes différentes par module)
 */
abstract class AbstractSensorRepository extends AbstractRepository
{
    abstract protected function getTableName(): string;

    /** @return string[] */
    abstract public function getSensorColumns(): array;

    public function getLatest(): ?array
    {
        $sql = "SELECT * FROM `" . $this->getTableName() . "` ORDER BY id DESC LIMIT 1";
        return $this->fetchOne($sql);
    }

    public function getRecent(int $limit = 50): array
    {
        $sql = "SELECT * FROM `" . $this->getTableName() . "` ORDER BY id DESC LIMIT " . max(1, min(200, $limit));
        return $this->fetchAll($sql);
    }

    public function getLastReadingDate(): ?string
    {
        $sql = "SELECT reading_time FROM `" . $this->getTableName() . "` ORDER BY id DESC LIMIT 1";
        $val = $this->fetchScalar($sql);
        return $val !== null ? (string) $val : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchBetween(string $start, string $end): array
    {
        $sql = "SELECT * FROM `" . $this->getTableName() . "` WHERE reading_time BETWEEN :s AND :e ORDER BY id ASC";
        return $this->fetchAll($sql, [':s' => $start, ':e' => $end]);
    }

    /**
     * @return array{min: float|null, max: float|null, avg: float|null, stddev: float|null}
     */
    public function getColumnStats(string $column, string $start, string $end): array
    {
        if (!in_array($column, $this->getSensorColumns(), true)) {
            return ['min' => null, 'max' => null, 'avg' => null, 'stddev' => null];
        }
        $sql = "SELECT MIN(`$column`) AS `min`, MAX(`$column`) AS `max`,
                       AVG(`$column`) AS `avg`, STDDEV(`$column`) AS `stddev`
                FROM `" . $this->getTableName() . "` WHERE reading_time BETWEEN :s AND :e";
        $row = $this->fetchOne($sql, [':s' => $start, ':e' => $end]);
        return [
            'min' => $row['min'] ?? null,
            'max' => $row['max'] ?? null,
            'avg' => $row['avg'] ?? null,
            'stddev' => $row['stddev'] ?? null,
        ];
    }

    public function countAll(): int
    {
        $sql = "SELECT COUNT(*) FROM `" . $this->getTableName() . "`";
        return (int) ($this->fetchScalar($sql) ?? 0);
    }

    public function countReadingsToday(): int
    {
        $sql = "SELECT COUNT(*) FROM `" . $this->getTableName() . "` WHERE reading_time >= :start AND reading_time <= :end";
        return (int) ($this->fetchScalar($sql, [
            ':start' => date('Y-m-d 00:00:00'),
            ':end' => date('Y-m-d 23:59:59'),
        ]) ?? 0);
    }

    public function getFirmwareVersion(): string
    {
        $sql = "SELECT version FROM `" . $this->getTableName() . "` ORDER BY id DESC LIMIT 1";
        return (string) ($this->fetchScalar($sql) ?? '-');
    }

    public function exportCsv(string $start, string $end, string $tmpFile): void
    {
        $rows = $this->fetchBetween($start, $end);
        $fp = fopen($tmpFile, 'w');
        if ($fp === false) {
            return;
        }
        if (!empty($rows)) {
            fputcsv($fp, array_keys($rows[0]), ';');
            foreach ($rows as $row) {
                fputcsv($fp, $row, ';');
            }
        }
        fclose($fp);
    }
}
