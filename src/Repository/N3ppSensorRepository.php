<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\N3ppSensorData;

/**
 * Repository pour l'insertion et la lecture des mesures serre/aquaponie (n3pp4_2) dans n3ppData.
 * Requetes preparees PDO uniquement.
 */
class N3ppSensorRepository extends AbstractRepository
{
    private const TABLE = 'n3ppData';

    /** Colonnes de capteurs disponibles pour les statistiques et graphiques. */
    public const SENSOR_COLUMNS = [
        'TempAir', 'Humidite', 'Luminosite',
        'Humid1', 'Humid2', 'Humid3', 'Humid4', 'HumidMoy',
        'PontDiv', 'bootCount', 'etatPompe', 'resetMode',
    ];

    public function insert(N3ppSensorData $data): void
    {
        $sql = "INSERT INTO " . self::TABLE . " (
            sensor, version,
            TempAir, Humidite, Luminosite,
            Humid1, Humid2, Humid3, Humid4, HumidMoy,
            PontDiv, WakeUp, ArrosageManu, SeuilSec, FreqWakeUp, SeuilPontDiv,
            mail, mailNotif, HeureArrosage, resetMode,
            etatPompe, tempsArrosage, bootCount
        ) VALUES (
            :sensor, :version,
            :tempAir, :humidite, :luminosite,
            :humid1, :humid2, :humid3, :humid4, :humidMoy,
            :pontDiv, :wakeUp, :arrosageManu, :seuilSec, :freqWakeUp, :seuilPontDiv,
            :mail, :mailNotif, :heureArrosage, :resetMode,
            :etatPompe, :tempsArrosage, :bootCount
        )";

        $this->execute($sql, [
            ':sensor' => $data->sensor,
            ':version' => $data->version,
            ':tempAir' => $data->tempAir,
            ':humidite' => $data->humidite,
            ':luminosite' => $data->luminosite,
            ':humid1' => $data->humid1,
            ':humid2' => $data->humid2,
            ':humid3' => $data->humid3,
            ':humid4' => $data->humid4,
            ':humidMoy' => $data->humidMoy,
            ':pontDiv' => $data->pontDiv,
            ':wakeUp' => $data->wakeUp,
            ':arrosageManu' => $data->arrosageManu,
            ':seuilSec' => $data->seuilSec,
            ':freqWakeUp' => $data->freqWakeUp,
            ':seuilPontDiv' => $data->seuilPontDiv,
            ':mail' => $data->mail,
            ':mailNotif' => $data->mailNotif,
            ':heureArrosage' => $data->heureArrosage,
            ':resetMode' => $data->resetMode,
            ':etatPompe' => $data->etatPompe,
            ':tempsArrosage' => $data->tempsArrosage,
            ':bootCount' => $data->bootCount,
        ]);
    }

    public function getLatest(): ?array
    {
        $sql = "SELECT * FROM `" . self::TABLE . "` ORDER BY id DESC LIMIT 1";
        return $this->fetchOne($sql);
    }

    public function getRecent(int $limit = 50): array
    {
        $sql = "SELECT * FROM `" . self::TABLE . "` ORDER BY id DESC LIMIT " . max(1, min(200, $limit));
        return $this->fetchAll($sql);
    }

    public function getLastReadingDate(): ?string
    {
        $sql = "SELECT reading_time FROM `" . self::TABLE . "` ORDER BY id DESC LIMIT 1";
        $val = $this->fetchScalar($sql);
        return $val !== null ? (string) $val : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchBetween(string $start, string $end): array
    {
        $sql = "SELECT * FROM `" . self::TABLE . "` WHERE reading_time BETWEEN :s AND :e ORDER BY id ASC";
        return $this->fetchAll($sql, [':s' => $start, ':e' => $end]);
    }

    /**
     * @return array{min: float|null, max: float|null, avg: float|null, stddev: float|null}
     */
    public function getColumnStats(string $column, string $start, string $end): array
    {
        $allowed = self::SENSOR_COLUMNS;
        if (!in_array($column, $allowed, true)) {
            return ['min' => null, 'max' => null, 'avg' => null, 'stddev' => null];
        }
        $sql = "SELECT MIN(`$column`) AS `min`, MAX(`$column`) AS `max`,
                       AVG(`$column`) AS `avg`, STDDEV(`$column`) AS `stddev`
                FROM `" . self::TABLE . "` WHERE reading_time BETWEEN :s AND :e";
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
        $sql = "SELECT COUNT(*) FROM `" . self::TABLE . "`";
        return (int) ($this->fetchScalar($sql) ?? 0);
    }

    public function getFirmwareVersion(): string
    {
        $sql = "SELECT version FROM `" . self::TABLE . "` ORDER BY id DESC LIMIT 1";
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
