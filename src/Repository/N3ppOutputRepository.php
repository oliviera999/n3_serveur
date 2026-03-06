<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Repository pour les outputs (controle) de la serre/aquaponie (n3pp).
 *
 * Le firmware n3pp4_2 attend un JSON avec des cles numeriques (indices de GPIO)
 * dont les valeurs sont les etats/parametres.
 */
class N3ppOutputRepository extends AbstractRepository
{
    private const TABLE = 'n3ppOutputs';

    /**
     * Retourne l'etat des outputs au format attendu par le firmware n3pp4_2.
     * Le firmware itere les cles JSON par indice (keys[0], keys[1], ...).
     *
     * @param int $board Numero de board (ex: 3)
     * @return array<string, string> Cles = gpio (string), valeurs = state
     */
    public function getStateForFirmware(int $board): array
    {
        $sql = "SELECT gpio, state FROM `" . self::TABLE . "` WHERE board = :board ORDER BY gpio ASC";
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
        $sql = "UPDATE `" . self::TABLE . "` SET state = :state WHERE gpio = :gpio AND board = :board";
        $this->execute($sql, [':state' => $state, ':gpio' => $gpio, ':board' => $board]);
    }

    /**
     * Tous les outputs d'une board (pour la page de contrôle).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllForBoard(int $board): array
    {
        $sql = "SELECT gpio, state FROM `" . self::TABLE . "` WHERE board = :board ORDER BY gpio ASC";
        return $this->fetchAll($sql, [':board' => $board]);
    }
}
