<?php

declare(strict_types=1);

namespace App\Repository;

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
