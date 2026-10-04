<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use App\Domain\EnergieSensorData;

/**
 * Repository des mesures du banc énergie (3 × INA226 : panneau / batterie / conso).
 * Hérite des lectures communes d'AbstractSensorRepository (getLatest, fetchBetween…).
 *
 * Table selon l'environnement : `energieData` (prod) / `energieDataTest` (energie_test),
 * toujours résolue via TableConfig. Famille « mesure seule » : pas d'outputs ni de heartbeat.
 */
class EnergieSensorRepository extends AbstractSensorRepository
{
    /**
     * Colonnes numériques (nom de colonne BDD = nom du champ POST firmware)
     * => propriété correspondante du DTO {@see EnergieSensorData}.
     * Liste fermée : les noms de colonnes ne proviennent jamais de l'entrée utilisateur.
     *
     * @var array<string, string>
     */
    private const NUMERIC_COLUMNS = [
        'PanneauV' => 'panneauV',
        'PanneauI' => 'panneauI',
        'PanneauP' => 'panneauP',
        'PanneauImax' => 'panneauImax',
        'BatterieV' => 'batterieV',
        'BatterieVmin' => 'batterieVmin',
        'BatterieI' => 'batterieI',
        'BatterieImin' => 'batterieImin',
        'BatterieImax' => 'batterieImax',
        'BatterieP' => 'batterieP',
        'BatterieVadc' => 'batterieVadc',
        'ConsoV' => 'consoV',
        'ConsoI' => 'consoI',
        'ConsoP' => 'consoP',
        'ConsoImax' => 'consoImax',
        'EnergiePanneauWh' => 'energiePanneauWh',
        'EnergieConsoWh' => 'energieConsoWh',
        'BatterieAh' => 'batterieAh',
        'BatterieSoc' => 'batterieSoc',
        'InaStatus' => 'inaStatus',
        'I2cErreurs' => 'i2cErreurs',
        'Rssi' => 'rssi',
        'FreeHeap' => 'freeHeap',
        'BootCount' => 'bootCount',
        'Uptime' => 'uptime',
    ];

    protected function getTableName(): string
    {
        return TableConfig::getEnergieDataTable();
    }

    /** @return string[] */
    public function getSensorColumns(): array
    {
        return array_keys(self::NUMERIC_COLUMNS);
    }

    public function insert(EnergieSensorData $data): void
    {
        $columns = ['sensor', 'version'];
        $placeholders = [':sensor', ':version'];
        $params = [
            ':sensor' => $data->sensor,
            ':version' => $data->version,
        ];

        foreach (self::NUMERIC_COLUMNS as $column => $property) {
            $columns[] = $column;
            $placeholders[] = ':' . $column;
            $params[':' . $column] = $data->{$property};
        }

        $columns[] = 'reading_time';
        $placeholders[] = ':reading_time';
        $params[':reading_time'] = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO `' . $this->getTableName() . '` (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $this->execute($sql, $params);
    }
}
