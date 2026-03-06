<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\MspSensorData;

/**
 * Repository pour l'insertion des mesures station meteo (msp2_5) dans msp1Data.
 * Requetes preparees PDO uniquement.
 */
class MspSensorRepository extends AbstractRepository
{
    private const TABLE = 'msp1Data';

    public function insert(MspSensorData $data): void
    {
        $sql = "INSERT INTO " . self::TABLE . " (
            sensor, version,
            TempAirInt, TempAirExt, HumidAirInt, HumidAirExt,
            LuminositeA, LuminositeB, LuminositeC, LuminositeD, LuminositeMoy,
            ServoHB, ServoGD, HumidSol, Pluie, TempEau, PontDiv,
            WakeUp, SeuilSec, FreqWakeUp, SeuilPontDiv,
            mail, mailNotif, resetMode, bootCount
        ) VALUES (
            :sensor, :version,
            :tempAirInt, :tempAirExt, :humidAirInt, :humidAirExt,
            :luminositeA, :luminositeB, :luminositeC, :luminositeD, :luminositeMoy,
            :servoHB, :servoGD, :humidSol, :pluie, :tempEau, :pontDiv,
            :wakeUp, :seuilSec, :freqWakeUp, :seuilPontDiv,
            :mail, :mailNotif, :resetMode, :bootCount
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
        ]);
    }
}
