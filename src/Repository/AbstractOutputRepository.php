<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Classe abstraite pour les repositories d'outputs MSP1 et N3PP.
 * Fournit les méthodes communes : getStateForFirmware, getLastBoardRequest, getAllForBoard.
 * Les sous-classes définissent getTable() et getStateKeyColumn().
 */
abstract class AbstractOutputRepository extends AbstractRepository
{
    public function __construct(
        PDO $pdo,
        protected BoardRepository $boardRepo,
    ) {
        parent::__construct($pdo);
    }

    abstract protected function getTable(): string;

    /**
     * Nom de la colonne utilisée comme clé pour les mises à jour (name pour MSP, gpio pour N3PP).
     */
    abstract protected function getStateKeyColumn(): string;

    /**
     * Retourne l'état des outputs pour le firmware (format JSON : gpio => state).
     *
     * @return array<string, string> Clés = numéros GPIO (string), valeurs = état (string)
     */
    public function getStateForFirmware(int $board): array
    {
        $table = $this->getTable();
        $sql = "SELECT gpio, state FROM `{$table}` WHERE board = :board";
        $rows = $this->fetchAll($sql, [':board' => $board]);

        $result = [];
        foreach ($rows as $row) {
            $gpio = (string) ($row['gpio'] ?? '');
            $result[$gpio] = (string) ($row['state'] ?? '');
        }

        // Mettre à jour last_request dans Boards (comportement legacy)
        $this->boardRepo->updateLastRequest((string) $board);

        return $result;
    }

    /**
     * Retourne la date/heure de la dernière requête du firmware pour cette board.
     */
    public function getLastBoardRequest(int $board): ?string
    {
        $row = $this->boardRepo->findByName((string) $board);
        return $row['last_request'] ?? null;
    }

    /**
     * Retourne tous les outputs d'une board pour l'affichage de la page contrôle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllForBoard(int $board): array
    {
        $table = $this->getTable();
        $sql = "SELECT id, name, board, gpio, state FROM `{$table}` WHERE board = :board ORDER BY id ASC";
        return $this->fetchAll($sql, [':board' => $board]);
    }
}
