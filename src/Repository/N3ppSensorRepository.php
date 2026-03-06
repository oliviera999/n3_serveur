<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\N3ppSensorData;

/**
 * Repository pour l'insertion des mesures serre/aquaponie (n3pp4_2) dans n3ppData.
 * Requetes preparees PDO uniquement.
 */
class N3ppSensorRepository extends AbstractRepository
{
    private const TABLE = 'n3ppData';

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
}
