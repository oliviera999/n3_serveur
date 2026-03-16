<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use App\Domain\N3ppSensorData;

/**
 * Repository pour l'insertion et la lecture des mesures serre/élevage (n3pp).
 * Hérite des méthodes communes d'AbstractSensorRepository.
 */
class N3ppSensorRepository extends AbstractSensorRepository
{
    protected function getTableName(): string
    {
        return TableConfig::getN3ppDataTable();
    }

    /**
     * Récupère la version du firmware de la dernière mesure enregistrée.
     *
     * @return string Version du firmware (ex: "4.20") ou "N/A" si aucune donnée
     */
    public function getFirmwareVersion(): string
    {
        $table = $this->getTableName();
        $sql = "SELECT version FROM `{$table}` ORDER BY reading_time DESC LIMIT 1";
        $result = $this->fetchOne($sql);
        return $result['version'] ?? 'N/A';
    }

    /** @return string[] */
    public function getSensorColumns(): array
    {
        return [
            'TempAir', 'Humidite', 'Luminosite',
            'Humid1', 'Humid2', 'Humid3', 'Humid4', 'HumidMoy',
            'PontDiv', 'bootCount', 'etatPompe', 'resetMode',
        ];
    }

    public function insert(N3ppSensorData $data): void
    {
        $sql = "INSERT INTO `" . $this->getTableName() . "` (
            sensor, version,
            TempAir, Humidite, Luminosite,
            Humid1, Humid2, Humid3, Humid4, HumidMoy,
            PontDiv, WakeUp, ArrosageManu, SeuilSec, FreqWakeUp, SeuilPontDiv,
            mail, mailNotif, HeureArrosage, resetMode,
            etatPompe, tempsArrosage, bootCount,
            reading_time
        ) VALUES (
            :sensor, :version,
            :tempAir, :humidite, :luminosite,
            :humid1, :humid2, :humid3, :humid4, :humidMoy,
            :pontDiv, :wakeUp, :arrosageManu, :seuilSec, :freqWakeUp, :seuilPontDiv,
            :mail, :mailNotif, :heureArrosage, :resetMode,
            :etatPompe, :tempsArrosage, :bootCount,
            :reading_time
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
            ':reading_time' => date('Y-m-d H:i:s'),
        ]);
    }
}
