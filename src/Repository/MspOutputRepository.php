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

    /**
     * Met a jour l'etat d'un output par son nom.
     */
    public function updateByName(string $name, string $state, int $board): void
    {
        $sql = "UPDATE `" . $this->getTable() . "` SET state = :state WHERE name = :name AND board = :board";
        $this->execute($sql, [':state' => $state, ':name' => $name, ':board' => $board]);
    }

    /**
     * Met a jour l'etat d'un output par son GPIO (compatibilite avec control-actions.js).
     */
    public function updateByGpio(int $gpio, string $state, int $board): void
    {
        $sql = "UPDATE `" . $this->getTable() . "` SET state = :state WHERE gpio = :gpio AND board = :board";
        $this->execute($sql, [':state' => $state, ':gpio' => $gpio, ':board' => $board]);
    }

    /**
     * Retourne un output par gpio et board (ex. GPIO 110 pour Reset ESP).
     *
     * @return array<string, mixed>|null
     */
    public function getOutputByGpioAndBoard(int $board, int $gpio): ?array
    {
        $sql = "SELECT id, name, gpio, state FROM `" . $this->getTable() . "` WHERE board = :board AND gpio = :gpio LIMIT 1";
        return $this->fetchOne($sql, [':board' => $board, ':gpio' => $gpio]);
    }

    /** Mapping GPIO 100-107 vers les noms de parametres (site initial MSP1). */
    private const PARAM_GPIO_MAP = [
        100 => 'mail',
        101 => 'mailNotif',
        102 => 'SeuilSec',
        103 => 'SeuilPontDiv',
        104 => 'ServoHB',
        105 => 'ServoGD',
        106 => 'WakeUp',
        107 => 'FreqWakeUp',
    ];

    /**
     * Retourne les parametres (GPIO 100-107) pour le formulaire « Changer les paramètres ».
     *
     * @return array<string, string>
     */
    public function getParametersForBoard(int $board): array
    {
        $sql = "SELECT gpio, state FROM `" . $this->getTable() . "` WHERE board = :board AND gpio IN (100, 101, 102, 103, 104, 105, 106, 107) ORDER BY gpio ASC";
        $rows = $this->fetchAll($sql, [':board' => $board]);
        $result = [];
        foreach (self::PARAM_GPIO_MAP as $gpio => $name) {
            $result[$name] = '';
        }
        foreach ($rows as $row) {
            $gpio = (int) ($row['gpio'] ?? 0);
            if (isset(self::PARAM_GPIO_MAP[$gpio])) {
                $result[self::PARAM_GPIO_MAP[$gpio]] = (string) ($row['state'] ?? '');
            }
        }
        return $result;
    }

    /**
     * Met a jour un seul parametre par son nom (pour API parameters temps reel).
     * Retourne true si le nom est reconnu et la mise a jour effectuee.
     */
    public function updateParameterByName(int $board, string $paramName, string $value): bool
    {
        $reverseMap = array_flip(self::PARAM_GPIO_MAP);
        if (!isset($reverseMap[$paramName])) {
            return false;
        }
        $this->updateByGpio($reverseMap[$paramName], $value, $board);
        return true;
    }

    /**
     * Met a jour en une fois les parametres (GPIO 100-107) depuis le formulaire output_create.
     *
     * @param array<string, string> $params Clés : mail, mailNotif, SeuilSec, SeuilPontDiv, ServoHB, ServoGD, WakeUp, FreqWakeUp
     */
    public function batchUpdateParameters(int $board, array $params): void
    {
        $reverseMap = array_flip(self::PARAM_GPIO_MAP);
        foreach ($params as $name => $value) {
            if (!isset($reverseMap[$name])) {
                continue;
            }
            $gpio = $reverseMap[$name];
            $state = is_scalar($value) ? (string) $value : '';
            $this->updateByGpio($gpio, $state, $board);
        }
    }
}
