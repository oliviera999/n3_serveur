<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use App\Domain\MspSensorData;

/**
 * Repository pour l'insertion et la lecture des mesures station meteo (msp2_5).
 * Table dynamique via TableConfig (msp1Data en prod, msp1DataTest en test).
 */
class MspSensorRepository extends AbstractRepository
{
    private static function table(): string
    {
        return TableConfig::getMspDataTable();
    }

    /** Colonnes de capteurs disponibles pour les statistiques et graphiques. */
    public const SENSOR_COLUMNS = [
        'TempAirInt', 'TempAirExt', 'HumidAirInt', 'HumidAirExt',
        'LuminositeA', 'LuminositeB', 'LuminositeC', 'LuminositeD', 'LuminositeMoy',
        'HumidSol', 'Pluie', 'TempEau', 'PontDiv', 'bootCount',
        'ServoHB', 'ServoGD', 'resetMode',
    ];

    public function insert(MspSensorData $data): void
    {
        $sql = "INSERT INTO `" . self::table() . "` (
            sensor, version,
            TempAirInt, TempAirExt, HumidAirInt, HumidAirExt,
            LuminositeA, LuminositeB, LuminositeC, LuminositeD, LuminositeMoy,
            ServoHB, ServoGD, HumidSol, Pluie, TempEau, PontDiv,
            WakeUp, SeuilSec, FreqWakeUp, SeuilPontDiv,
            mail, mailNotif, resetMode, bootCount,
            reading_time
        ) VALUES (
            :sensor, :version,
            :tempAirInt, :tempAirExt, :humidAirInt, :humidAirExt,
            :luminositeA, :luminositeB, :luminositeC, :luminositeD, :luminositeMoy,
            :servoHB, :servoGD, :humidSol, :pluie, :tempEau, :pontDiv,
            :wakeUp, :seuilSec, :freqWakeUp, :seuilPontDiv,
            :mail, :mailNotif, :resetMode, :bootCount,
            :reading_time
        )";

        $this->execute($sql, [
            ':sensor' => $data->sensor,
            ':version' => $data->version,
            ':tempAirInt' => $data->tempAirInt,
            ':tempAirExt' => $data->tempAirExt,
            ':humidAirInt' => $data->humidAirInt,
            ':humidAirExt' => $data->humidAirExt,
            ':luminositeA' => $data->luminositeA,
            ':luminositeB' => $data->luminositeB,
            ':luminositeC' => $data->luminositeC,
            ':luminositeD' => $data->luminositeD,
            ':luminositeMoy' => $data->luminositeMoy,
            ':servoHB' => $data->servoHB,
            ':servoGD' => $data->servoGD,
            ':humidSol' => $data->humidSol,
            ':pluie' => $data->pluie,
            ':tempEau' => $data->tempEau,
            ':pontDiv' => $data->pontDiv,
            ':wakeUp' => $data->wakeUp,
            ':seuilSec' => $data->seuilSec,
            ':freqWakeUp' => $data->freqWakeUp,
            ':seuilPontDiv' => $data->seuilPontDiv,
            ':mail' => $data->mail,
            ':mailNotif' => $data->mailNotif,
            ':resetMode' => $data->resetMode,
            ':bootCount' => $data->bootCount,
            ':reading_time' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getLatest(): ?array
    {
        $sql = "SELECT * FROM `" . self::table() . "` ORDER BY id DESC LIMIT 1";
        return $this->fetchOne($sql);
    }

    public function getRecent(int $limit = 50): array
    {
        $sql = "SELECT * FROM `" . self::table() . "` ORDER BY id DESC LIMIT " . max(1, min(200, $limit));
        return $this->fetchAll($sql);
    }

    public function getLastReadingDate(): ?string
    {
        $sql = "SELECT reading_time FROM `" . self::table() . "` ORDER BY id DESC LIMIT 1";
        $val = $this->fetchScalar($sql);
        return $val !== null ? (string) $val : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchBetween(string $start, string $end): array
    {
        $sql = "SELECT * FROM `" . self::table() . "` WHERE reading_time BETWEEN :s AND :e ORDER BY id ASC";
        return $this->fetchAll($sql, [':s' => $start, ':e' => $end]);
    }

    /**
     * Statistiques (min, max, avg, stddev) pour une colonne sur une plage de dates.
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
                FROM `" . self::table() . "` WHERE reading_time BETWEEN :s AND :e";
        $row = $this->fetchOne($sql, [':s' => $start, ':e' => $end]);
        return [
            'min' => $row['min'] ?? null,
            'max' => $row['max'] ?? null,
            'avg' => $row['avg'] ?? null,
            'stddev' => $row['stddev'] ?? null,
        ];
    }

    /** Nombre total d'enregistrements. */
    public function countAll(): int
    {
        $sql = "SELECT COUNT(*) FROM `" . self::table() . "`";
        return (int) ($this->fetchScalar($sql) ?? 0);
    }

    /** Version firmware de la dernière mesure. */
    public function getFirmwareVersion(): string
    {
        $sql = "SELECT version FROM `" . self::table() . "` ORDER BY id DESC LIMIT 1";
        return (string) ($this->fetchScalar($sql) ?? '-');
    }

    /** Export CSV vers un fichier temporaire. */
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
