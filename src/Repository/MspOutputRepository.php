<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\MspGpioMap;
use App\Config\TableConfig;
use PDO;

/**
 * Repository pour les outputs (controle) de la station meteo (msp1).
 * Table dynamique via TableConfig (msp1Outputs en prod, Msp1OutputsTest en test).
 * Hérite des méthodes communes d'AbstractOutputRepository.
 */
class MspOutputRepository extends AbstractOutputRepository
{
    public function __construct(PDO $pdo, BoardRepository $boardRepo)
    {
        parent::__construct($pdo, $boardRepo);
    }

    protected function getTable(): string
    {
        return TableConfig::getMspOutputsTable();
    }

    protected function getStateKeyColumn(): string
    {
        return 'name';
    }

    protected function getParamGpioMap(): array
    {
        return MspGpioMap::paramGpioMap();
    }

    /**
     * Met a jour l'etat d'un output par son nom.
     */
    public function updateByName(string $name, string $state, int $board): void
    {
        $sql = 'UPDATE `' . $this->getTable() . '` SET state = :state WHERE name = :name AND board = :board';
        $this->execute($sql, [':state' => $state, ':name' => $name, ':board' => $board]);
    }
}
