<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Classe abstraite pour les repositories d'outputs MSP1 et N3PP.
 *
 * Fournit les méthodes communes : getStateForFirmware, getAllForBoard,
 * getLastBoardRequest, updateLastBoardRequest.
 */
abstract class AbstractOutputRepository extends AbstractRepository
{
    abstract protected function getTable(): string;
    abstract protected function getStateKeyColumn(): string;

    /**
     * Retourne l'état des outputs pour le firmware (format key => state).
     * Met à jour last_request dans Boards après lecture.
     *
     * @return array<int|string, string> gpio=>state (n3pp) ou name=>state (msp1)
     */
    public function getStateForFirmware(int $board): array
    {
        $table = $this->getTable();
        $keyCol = $this->getStateKeyColumn();
        $sql = "SELECT {$keyCol}, state FROM `{$table}` WHERE board = :board";
        $rows = $this->fetchAll($sql, [':board' => $board]);

        $result = [];
        foreach ($rows as $row) {
            $key = $row[$keyCol] ?? null;
            if ($key !== null) {
                $result[$key] = (string) ($row['state'] ?? '0');
            }
        }

        $this->updateLastBoardRequest($board);
        return $result;
    }

    /**
     * Retourne tous les outputs d'une board pour l'affichage page contrôle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllForBoard(int $board): array
    {
        $table = $this->getTable();
        $sql = "SELECT id, name, state FROM `{$table}` WHERE board = :board ORDER BY id ASC";
        return $this->fetchAll($sql, [':board' => $board]);
    }

    /**
     * Retourne la dernière requête de la board (Boards.last_request).
     *
     * @return array{board: string, last_request: string|null}|null
     */
    public function getLastBoardRequest(int $board): ?array
    {
        $sql = "SELECT board, last_request FROM Boards WHERE board = :board";
        $row = $this->fetchOne($sql, [':board' => $board]);
        if ($row === null) {
            return null;
        }
        return [
            'board' => (string) $row['board'],
            'last_request' => $row['last_request'] !== null ? (string) $row['last_request'] : null,
        ];
    }

    /**
     * Met à jour last_request dans Boards après un getState du firmware.
     */
    protected function updateLastBoardRequest(int $board): bool
    {
        $sql = "UPDATE Boards SET last_request = NOW() WHERE board = :board";
        return $this->execute($sql, [':board' => $board]);
    }
}
