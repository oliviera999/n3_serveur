<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use App\Domain\MspSensorData;

/**
 * Repository pour l'insertion et la lecture des mesures station météo (msp).
 * Hérite des méthodes communes d'AbstractSensorRepository.
 */
class MspSensorRepository extends AbstractSensorRepository
{
    protected function getTableName(): string
    {
        return TableConfig::getMspDataTable();
    }

    /** @return string[] */
    public function getSensorColumns(): array
    {
        return [
            'TempAirInt', 'TempAirExt', 'HumidAirInt', 'HumidAirExt', 'Pression',
            'LuminositeA', 'LuminositeB', 'LuminositeC', 'LuminositeD', 'LuminositeMoy',
            'HumidSol', 'Pluie', 'TempEau', 'PontDiv', 'bootCount',
            'ServoHB', 'ServoGD', 'resetMode',
        ];
    }

    public function insert(MspSensorData $data): void
    {
        $sql = 'INSERT INTO `' . $this->getTableName() . '` (
            sensor, version,
            TempAirInt, TempAirExt, HumidAirInt, HumidAirExt, Pression,
            LuminositeA, LuminositeB, LuminositeC, LuminositeD, LuminositeMoy,
            ServoHB, ServoGD, HumidSol, Pluie, TempEau, PontDiv,
            WakeUp, SeuilSec, FreqWakeUp, SeuilPontDiv,
            mail, mailNotif, resetMode, bootCount,
            reading_time
        ) VALUES (
            :sensor, :version,
            :tempAirInt, :tempAirExt, :humidAirInt, :humidAirExt, :pression,
            :luminositeA, :luminositeB, :luminositeC, :luminositeD, :luminositeMoy,
            :servoHB, :servoGD, :humidSol, :pluie, :tempEau, :pontDiv,
            :wakeUp, :seuilSec, :freqWakeUp, :seuilPontDiv,
            :mail, :mailNotif, :resetMode, :bootCount,
            :reading_time
        )';

        $this->execute($sql, [
            ':sensor' => $data->sensor,
            ':version' => $data->version,
            ':tempAirInt' => $data->tempAirInt,
            ':tempAirExt' => $data->tempAirExt,
            ':humidAirInt' => $data->humidAirInt,
            ':humidAirExt' => $data->humidAirExt,
            ':pression' => $data->pression,
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
}
