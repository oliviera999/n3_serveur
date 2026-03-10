<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Classe abstraite pour les repositories de capteurs MSP1 et N3PP.
 * Fournit les méthodes communes : getLatest, fetchBetween, getLastReadingDate, countReadingsToday.
 * Les sous-classes définissent getTableName() et getSensorColumns().
 */
abstract class AbstractSensorRepository extends AbstractRepository
{
    abstract protected function getTableName(): string;

    /** @return string[] */
    abstract public function getSensorColumns(): array;

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
     * Retourne les mesures entre deux dates (incluses).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBetween(string $start, string $end): array
    {
        $table = $this->getTableName();
        $sql = "SELECT * FROM `{$table}` WHERE reading_time BETWEEN :start AND :end ORDER BY reading_time ASC";
        return $this->fetchAll($sql, [':start' => $start, ':end' => $end]);
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
}
