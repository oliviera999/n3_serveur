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
     * Mapping GPIO vers noms de paramètres.
     *
     * @return array<int, string>
     */
    abstract protected function getParamGpioMap(): array;

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

    /**
     * Retourne les paramètres (GPIO de configuration) pour le formulaire « Changer les paramètres ».
     *
     * @return array<string, string>
     */
    public function getParametersForBoard(int $board): array
    {
        $map = $this->getParamGpioMap();
        $gpioList = implode(', ', array_map(static fn(int $gpio): string => (string) $gpio, array_keys($map)));
        $sql = "SELECT gpio, state FROM `" . $this->getTable() . "` WHERE board = :board AND gpio IN ({$gpioList}) ORDER BY gpio ASC";
        $rows = $this->fetchAll($sql, [':board' => $board]);

        $result = [];
        foreach ($map as $name) {
            $result[$name] = '';
        }
        foreach ($rows as $row) {
            $gpio = (int) ($row['gpio'] ?? 0);
            if (isset($map[$gpio])) {
                $result[$map[$gpio]] = (string) ($row['state'] ?? '');
            }
        }
        return $result;
    }

    /**
     * Met a jour un seul parametre par son nom.
     */
    public function updateParameterByName(int $board, string $paramName, string $value): bool
    {
        $reverseMap = array_flip($this->getParamGpioMap());
        if (!isset($reverseMap[$paramName])) {
            return false;
        }
        $this->updateByGpio((int) $reverseMap[$paramName], $value, $board);
        return true;
    }

    /**
     * Met a jour en une fois les parametres depuis le formulaire output_create.
     *
     * @param array<string, mixed> $params
     */
    public function batchUpdateParameters(int $board, array $params): void
    {
        $reverseMap = array_flip($this->getParamGpioMap());
        foreach ($params as $name => $value) {
            if (!isset($reverseMap[$name])) {
                continue;
            }
            $state = is_scalar($value) ? (string) $value : '';
            $this->updateByGpio((int) $reverseMap[$name], $state, $board);
        }
    }

    /**
     * Met a jour l'etat d'un output par son GPIO.
     */
    abstract public function updateByGpio(int $gpio, string $state, int $board): void;
}
