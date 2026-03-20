<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use PDO;

/**
 * Repository pour les outputs (controle) de la serre/aquaponie (n3pp).
 * Table dynamique via TableConfig (n3ppOutputs en prod, n3ppOutputsTest en test).
 * Hérite des méthodes communes d'AbstractOutputRepository.
 */
class N3ppOutputRepository extends AbstractOutputRepository
{
    public function __construct(PDO $pdo, BoardRepository $boardRepo)
    {
        parent::__construct($pdo, $boardRepo);
    }

    protected function getTable(): string
    {
        return TableConfig::getN3ppOutputsTable();
    }

    protected function getStateKeyColumn(): string
    {
        return 'gpio';
    }

    protected function getParamGpioMap(): array
    {
        return self::PARAM_GPIO_MAP;
    }

    /**
     * Met a jour l'etat d'un output par son GPIO.
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

    /**
     * Retourne les N premières sorties (par id) pour la page de contrôle (site initial : 3 sorties).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPartOutputs(int $board, int $limit = 3): array
    {
        $sql = "SELECT id, name, gpio, state FROM `" . $this->getTable() . "` WHERE board = :board ORDER BY id ASC LIMIT " . (int) $limit;
        return $this->fetchAll($sql, [':board' => $board]);
    }

    /**
     * Met a jour l'etat d'un output par son id.
     */
    public function updateById(int $id, string $state): void
    {
        $sql = "UPDATE `" . $this->getTable() . "` SET state = :state WHERE id = :id";
        $this->execute($sql, [':state' => $state, ':id' => $id]);
    }

    /**
     * Supprime un output par son id. Retourne le board de la ligne supprimée ou null.
     */
    public function deleteById(int $id): ?int
    {
        $row = $this->fetchOne("SELECT board FROM `" . $this->getTable() . "` WHERE id = :id", [':id' => $id]);
        if ($row === null) {
            return null;
        }
        $board = (int) $row['board'];
        $this->execute("DELETE FROM `" . $this->getTable() . "` WHERE id = :id", [':id' => $id]);
        return $board;
    }

    /**
     * Compte le nombre d'outputs pour une board (pour supprimer la board si 0, comportement site initial).
     */
    public function countForBoard(int $board): int
    {
        $sql = "SELECT COUNT(*) FROM `" . $this->getTable() . "` WHERE board = :board";
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

}
