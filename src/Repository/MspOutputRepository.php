<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;

/**
 * Repository pour les outputs (controle) de la station meteo (msp1).
 * Table dynamique via TableConfig (msp1Outputs en prod, Msp1OutputsTest en test).
 * Hérite des méthodes communes d'AbstractOutputRepository.
 */
class MspOutputRepository extends AbstractOutputRepository
{
    protected function getTable(): string
    {
        return TableConfig::getMspOutputsTable();
    }

    protected function getStateKeyColumn(): string
    {
        return 'name';
    }

    /**
     * Met a jour l'etat d'un output par son nom.
     */
    public function updateByName(string $name, string $state, int $board): void
    {
        $sql = "UPDATE `" . $this->getTable() . "` SET state = :state WHERE name = :name AND board = :board";
        $this->execute($sql, [':state' => $state, ':name' => $name, ':board' => $board]);
    }
}
