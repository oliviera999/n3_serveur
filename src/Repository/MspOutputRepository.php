<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Repository pour les outputs (controle) de la station meteo (msp1).
 *
 * Le firmware msp2_5 attend un JSON avec des cles nommees :
 *   resetMode, mail, mailNotif, SeuilSec, SeuilPontDiv,
 *   AngleServoHB, AngleServoGD, WakeUp, FreqWakeUp
 */
class MspOutputRepository extends AbstractRepository
{
    private const TABLE = 'msp1Outputs';

    /**
     * Retourne l'etat des outputs au format attendu par le firmware msp2_5.
     * Le firmware lit les proprietes par nom (myObject["resetMode"], etc.)
     *
     * @param int $board Numero de board (ex: 2)
     * @return array<string, string>
     */
    public function getStateForFirmware(int $board): array
    {
        $sql = "SELECT name, state FROM `" . self::TABLE . "` WHERE board = :board";
        $rows = $this->fetchAll($sql, [':board' => $board]);

        $result = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? '';
            if ($name !== '') {
                $result[$name] = $row['state'] ?? '0';
            }
        }
        return $result;
    }

    /**
     * Met a jour l'etat d'un output par son nom.
     */
    public function updateByName(string $name, string $state, int $board): void
    {
        $sql = "UPDATE `" . self::TABLE . "` SET state = :state WHERE name = :name AND board = :board";
        $this->execute($sql, [':state' => $state, ':name' => $name, ':board' => $board]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllForBoard(int $board): array
    {
        $sql = "SELECT id, name, gpio, state FROM `" . self::TABLE . "` WHERE board = :board ORDER BY gpio ASC";
        return $this->fetchAll($sql, [':board' => $board]);
    }

    /** Dernière requête de la board dans la table Boards. */
    public function getLastBoardRequest(int $board): ?string
    {
        $sql = "SELECT last_request FROM `Boards` WHERE board = :board LIMIT 1";
        $val = $this->fetchScalar($sql, [':board' => $board]);
        return $val !== null ? (string) $val : null;
    }
}
