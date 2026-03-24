<?php

declare(strict_types=1);

namespace App\Repository;

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
        return self::PARAM_GPIO_MAP;
    }

    /**
     * Met a jour l'etat d'un output par son nom.
     */
    public function updateByName(string $name, string $state, int $board): void
    {
        $sql = "UPDATE `" . $this->getTable() . "` SET state = :state WHERE name = :name AND board = :board";
        $this->execute($sql, [':state' => $state, ':name' => $name, ':board' => $board]);
    }

    /** Mapping GPIO virtuels vers les noms de parametres (site initial MSP1 + extensions). */
    private const PARAM_GPIO_MAP = [
        100 => 'mail',
        101 => 'mailNotif',
        102 => 'SeuilSec',
        103 => 'SeuilPontDiv',
        104 => 'ServoHB',
        105 => 'ServoGD',
        106 => 'WakeUp',
        107 => 'FreqWakeUp',
        111 => 'ServoModeAuto',
    ];

}
