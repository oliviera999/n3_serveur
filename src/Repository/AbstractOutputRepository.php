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
    /** @var int[] GPIO de commande one-shot (envoyés puis acquittés à 0) */
    private const FFP3_ONE_SHOT_GPIOS = [13, 108, 109, 110];

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
     * GPIO server-only exclus des réponses firmware (sous-classes MSP/N3PP).
     *
     * @return int[]
     */
    protected function getServerOnlyGpios(): array
    {
        return \App\Config\NotificationPolicyGpioMap::serverOnlyGpiosForMspN3pp();
    }

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
        $gpioToAck = [];
        $serverOnly = array_flip($this->getServerOnlyGpios());
        foreach ($rows as $row) {
            $gpio = (string) ($row['gpio'] ?? '');
            $gpioInt = (int) $gpio;
            if (isset($serverOnly[$gpioInt])) {
                continue;
            }
            $state = (string) ($row['state'] ?? '');
            $result[$gpio] = $state;

            if (in_array($gpioInt, self::FFP3_ONE_SHOT_GPIOS, true) && $this->isActiveState($state)) {
                $gpioToAck[] = $gpioInt;
            }
        }

        // Acquittement one-shot (108/109/110) :
        // le firmware reçoit la commande "1" puis le serveur repasse immédiatement à 0
        // pour éviter les boucles de déclenchement au prochain cycle.
        foreach (array_values(array_unique($gpioToAck)) as $gpioInt) {
            $this->updateByGpio($gpioInt, '0', $board);
        }

        // Mettre à jour last_request dans Boards (comportement legacy)
        $this->boardRepo->updateLastRequest((string) $board);

        return $result;
    }

    private function isActiveState(string $state): bool
    {
        $normalized = strtolower(trim($state));
        return in_array($normalized, ['1', 'true', 'on', 'checked', 'yes'], true);
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
        $gpioList = implode(', ', array_map(static fn (int $gpio): string => (string) $gpio, array_keys($map)));
        $sql = 'SELECT gpio, state FROM `' . $this->getTable() . "` WHERE board = :board AND gpio IN ({$gpioList}) ORDER BY gpio ASC";
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
    public function updateByGpio(int $gpio, string $state, int $board): void
    {
        $sql = 'UPDATE `' . $this->getTable() . '` SET state = :state WHERE gpio = :gpio AND board = :board';
        $this->execute($sql, [':state' => $state, ':gpio' => $gpio, ':board' => $board]);
    }

    /**
     * Retourne un output par gpio et board (ex. GPIO 110 pour Reset ESP).
     *
     * @return array<string, mixed>|null
     */
    public function getOutputByGpioAndBoard(int $board, int $gpio): ?array
    {
        $sql = 'SELECT id, name, gpio, state FROM `' . $this->getTable() . '` WHERE board = :board AND gpio = :gpio LIMIT 1';
        return $this->fetchOne($sql, [':board' => $board, ':gpio' => $gpio]);
    }
}
