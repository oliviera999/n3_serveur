<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;

/**
 * Repository pour les outputs (controle) de la serre/aquaponie (n3pp).
 * Table dynamique via TableConfig (n3ppOutputs en prod, n3ppOutputsTest en test).
 */
class N3ppOutputRepository extends AbstractRepository
{
    private static function table(): string
    {
        return TableConfig::getN3ppOutputsTable();
    }

    /**
     * Retourne l'etat des outputs au format attendu par le firmware n3pp4_2.
     * Le firmware itere les cles JSON par indice (keys[0], keys[1], ...).
     *
     * @param int $board Numero de board (ex: 3)
     * @return array<string, string> Cles = gpio (string), valeurs = state
     */
    public function getStateForFirmware(int $board): array
    {
        $sql = "SELECT gpio, state FROM `" . self::table() . "` WHERE board = :board ORDER BY gpio ASC";
        $rows = $this->fetchAll($sql, [':board' => $board]);

        $result = [];
        foreach ($rows as $row) {
            $gpio = (string) ($row['gpio'] ?? '');
            if ($gpio !== '') {
                $result[$gpio] = $row['state'] ?? '0';
            }
        }
        return $result;
    }

    /**
     * Met a jour l'etat d'un output par son GPIO.
     */
    public function updateByGpio(int $gpio, string $state, int $board): void
    {
        $sql = "UPDATE `" . self::table() . "` SET state = :state WHERE gpio = :gpio AND board = :board";
        $this->execute($sql, [':state' => $state, ':gpio' => $gpio, ':board' => $board]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllForBoard(int $board): array
    {
        $sql = "SELECT id, name, gpio, state FROM `" . self::table() . "` WHERE board = :board ORDER BY gpio ASC";
        return $this->fetchAll($sql, [':board' => $board]);
    }

    public function getLastBoardRequest(int $board): ?string
    {
        $sql = "SELECT last_request FROM `Boards` WHERE board = :board LIMIT 1";
        $val = $this->fetchScalar($sql, [':board' => $board]);
        return $val !== null ? (string) $val : null;
    }
}
