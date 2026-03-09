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

    /**
     * Retourne un output par gpio et board (ex. GPIO 110 pour Reset ESP).
     *
     * @return array<string, mixed>|null
     */
    public function getOutputByGpioAndBoard(int $board, int $gpio): ?array
    {
        $sql = "SELECT id, name, gpio, state FROM `" . self::table() . "` WHERE board = :board AND gpio = :gpio LIMIT 1";
        return $this->fetchOne($sql, [':board' => $board, ':gpio' => $gpio]);
    }

    /**
     * Retourne les N premières sorties (par id) pour la page de contrôle (site initial : 3 sorties).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPartOutputs(int $board, int $limit = 3): array
    {
        $sql = "SELECT id, name, gpio, state FROM `" . self::table() . "` WHERE board = :board ORDER BY id ASC LIMIT " . (int) $limit;
        return $this->fetchAll($sql, [':board' => $board]);
    }

    /**
     * Met a jour l'etat d'un output par son id.
     */
    public function updateById(int $id, string $state): void
    {
        $sql = "UPDATE `" . self::table() . "` SET state = :state WHERE id = :id";
        $this->execute($sql, [':state' => $state, ':id' => $id]);
    }

    /**
     * Supprime un output par son id. Retourne le board de la ligne supprimée ou null.
     */
    public function deleteById(int $id): ?int
    {
        $row = $this->fetchOne("SELECT board FROM `" . self::table() . "` WHERE id = :id", [':id' => $id]);
        if ($row === null) {
            return null;
        }
        $board = (int) $row['board'];
        $this->execute("DELETE FROM `" . self::table() . "` WHERE id = :id", [':id' => $id]);
        return $board;
    }

    /**
     * Compte le nombre d'outputs pour une board (pour supprimer la board si 0, comportement site initial).
     */
    public function countForBoard(int $board): int
    {
        $sql = "SELECT COUNT(*) FROM `" . self::table() . "` WHERE board = :board";
        $val = $this->fetchScalar($sql, [':board' => $board]);
        return (int) $val;
    }

    /**
     * Supprime la ligne Boards pour cette board si plus aucun output (comportement site initial).
     */
    public function deleteBoardIfEmpty(int $board): void
    {
        if ($this->countForBoard($board) === 0) {
            $this->execute("DELETE FROM `Boards` WHERE board = :board", [':board' => $board]);
        }
    }

    /** Mapping GPIO 100-107 vers les noms de parametres (site initial). */
    private const PARAM_GPIO_MAP = [
        100 => 'mail',
        101 => 'mailNotif',
        102 => 'SeuilSec',
        103 => 'SeuilPontDiv',
        104 => 'HeureArrosage',
        105 => 'tempsArrosage',
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
        $sql = "SELECT gpio, state FROM `" . self::table() . "` WHERE board = :board AND gpio IN (100, 101, 102, 103, 104, 105, 106, 107) ORDER BY gpio ASC";
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
     * Met a jour en une fois les parametres (GPIO 100-107) depuis le formulaire output_create.
     *
     * @param array<string, string> $params Clés : mail, mailNotif, SeuilSec, SeuilPontDiv, HeureArrosage, tempsArrosage, WakeUp, FreqWakeUp
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
}
