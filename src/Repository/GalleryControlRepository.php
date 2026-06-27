<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;

/**
 * Repository des sorties de controle des galeries ESP32-CAM.
 *
 * Les tables sont historiques (une par galerie) et doivent rester
 * strictement whitelistees pour eviter toute injection SQL.
 */
class GalleryControlRepository extends AbstractRepository
{
    /** @var array<string, array{board:int, table:string, label:string}> */
    private const MODULES = [
        'msp1' => ['board' => 6, 'table' => 'UploadPhoto2Outputs', 'label' => 'MSP1'],
        'n3pp' => ['board' => 7, 'table' => 'UploadPhoto3Outputs', 'label' => 'N3PP'],
        'ffp3' => ['board' => 5, 'table' => 'UploadPhoto1Outputs', 'label' => 'FFP3'],
    ];

    /** @var array<string, int> */
    private const PARAM_GPIO_MAP = [
        'mail' => 102,
        'mailNotif' => 103,
        'forceWakeUp' => 104,
        'sleepTime' => 105,
        'resetMode' => 106,
        'notifMode' => 107,
        'notifCategories' => 108,
    ];

    /** @var int[] */
    private const SERVER_ONLY_GPIOS = [107, 108];

    public function __construct(
        \PDO $pdo,
        private readonly BoardRepository $boardRepository,
    ) {
        parent::__construct($pdo);
    }

    /**
     * @return array{board:int, table:string, label:string}
     */
    public function getModule(string $slug): array
    {
        if (!isset(self::MODULES[$slug])) {
            throw new InvalidArgumentException('Slug galerie invalide: ' . $slug);
        }

        return self::MODULES[$slug];
    }

    /**
     * @return array<string, string>
     */
    public function getStateForFirmware(string $slug): array
    {
        $module = $this->getModule($slug);
        $table = $module['table'];
        $board = $module['board'];

        $sql = "SELECT gpio, state FROM `{$table}` WHERE board = :board";
        $rows = $this->fetchAll($sql, [':board' => $board]);

        $state = [];
        $serverOnly = array_flip(self::SERVER_ONLY_GPIOS);
        foreach ($rows as $row) {
            $gpio = (string) ($row['gpio'] ?? '');
            if ($gpio === '' || isset($serverOnly[(int) $gpio])) {
                continue;
            }
            $state[$gpio] = (string) ($row['state'] ?? '');
        }

        $this->boardRepository->updateLastRequest((string) $board);

        return $state;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOutputsForControlPage(string $slug): array
    {
        $module = $this->getModule($slug);
        $table = $module['table'];
        $board = $module['board'];

        $sql = "SELECT id, name, board, gpio, state, requestTime FROM `{$table}` WHERE board = :board ORDER BY id ASC";
        return $this->fetchAll($sql, [':board' => $board]);
    }

    /**
     * @return array<string, string>
     */
    public function getParametersForControlPage(string $slug): array
    {
        $module = $this->getModule($slug);
        $table = $module['table'];
        $board = $module['board'];

        $sql = "SELECT gpio, state FROM `{$table}` WHERE board = :board";
        $rows = $this->fetchAll($sql, [':board' => $board]);
        $byGpio = [];
        foreach ($rows as $row) {
            $byGpio[(int) ($row['gpio'] ?? 0)] = (string) ($row['state'] ?? '');
        }

        $params = [];
        foreach (self::PARAM_GPIO_MAP as $name => $gpio) {
            // Défauts alignés sur le firmware quand aucune valeur n'est stockée :
            // sleepTime = 600 s (TIME_TO_SLEEP), mail vide, mailNotif désactivé, le reste à 0.
            $default = match ($name) {
                'mail' => '',
                'mailNotif' => 'false',
                'sleepTime' => '600',
                default => '0',
            };
            $params[$name] = $byGpio[$gpio] ?? $default;
        }

        return $params;
    }

    public function getLastBoardRequest(string $slug): ?string
    {
        $module = $this->getModule($slug);
        $board = (string) $module['board'];
        $row = $this->boardRepository->findByName($board);

        return $row['last_request'] ?? null;
    }

    public function getFirmwareVersion(string $slug): ?string
    {
        $module = $this->getModule($slug);
        $table = $module['table'];
        $board = $module['board'];

        $sql = "SELECT state FROM `{$table}` WHERE board = :board AND gpio = 100 LIMIT 1";
        $value = $this->fetchScalar($sql, [':board' => $board]);
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    public function updateByGpio(string $slug, int $gpio, string $state): void
    {
        $module = $this->getModule($slug);
        $table = $module['table'];
        $board = $module['board'];

        $sql = "UPDATE `{$table}` SET state = :state, requestTime = NOW() WHERE board = :board AND gpio = :gpio";
        $this->execute($sql, [
            ':state' => $state,
            ':board' => $board,
            ':gpio' => $gpio,
        ]);
    }

    public function updateParameterByName(string $slug, string $paramName, string $value): bool
    {
        if (!isset(self::PARAM_GPIO_MAP[$paramName])) {
            return false;
        }

        $gpio = self::PARAM_GPIO_MAP[$paramName];
        $this->updateByGpio($slug, $gpio, $value);
        return true;
    }

    public function updateFirmwareVersion(string $slug, string $version): void
    {
        $this->updateByGpio($slug, 100, $version);
    }
}
