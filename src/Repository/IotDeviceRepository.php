<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Catalogue d'appareils IoT (instances de familles déjà connues).
 * Table globale `iotDevices` — pas liée à TableConfig / ENV.
 * Créée et semée à la volée si absente (même pattern que NavPageRepository).
 */
class IotDeviceRepository extends AbstractRepository
{
    private const TABLE = 'iotDevices';

    /** Familles autorisées pour le wizard Supervision. */
    public const FAMILIES = ['ffp3', 'msp1', 'n3pp', 'energie', 'pgl', 'gallery'];

    /**
     * Appareils déployés connus (seed). Format :
     * [device_id, family, board, label, env, page_key, page_url, live_module_id, live_health_url, sort_order]
     *
     * @var list<array{0:string,1:string,2:string|null,3:string,4:string,5:string|null,6:string|null,7:string|null,8:string|null,9:int}>
     */
    private const SEED = [
        ['n3-ffp3-wroom-prod', 'ffp3', '1', 'Aquaponie (WROOM prod)', 'prod', 'aquaponie', '/aquaponie', 'ffp3-wroom-prod', '/api/realtime/system/health', 10],
        ['n3-ffp3-wroom-test', 'ffp3', '2', 'Aquaponie (WROOM test)', 'test', 'aquaponie-test', '/aquaponie-test', 'ffp3-wroom-test', '/api/realtime-test/system/health', 20],
        ['n3-ffp3-s3-prod', 'ffp3', '4', 'Aquamobile (S3 prod)', 's3', 'aquamobile', '/aquamobile', 'ffp3-s3-prod', '/api/realtime3/system/health', 30],
        ['n3-ffp3-s3-test', 'ffp3', '5', 'Aquamobile (S3 test3)', 'test3', 'aquamobile-test', '/aquamobile-test', 'ffp3-s3-test', '/api/realtime3-test/system/health', 40],
        ['n3-msp-01', 'msp1', '2', 'Potager (MSP1 prod)', 'prod', 'potager', '/meteo', 'msp1-prod', '/msp1/api/realtime/system/health', 50],
        ['n3-msp-test', 'msp1', '2', 'Potager (MSP1 test)', 'msp_test', 'potager-test', '/msp1-test/msp1datas/msp1-data.php', 'msp1-test', '/msp1-test/api/realtime/system/health', 60],
        ['n3-n3pp-01', 'n3pp', '3', 'Élevage (N3PP prod)', 'prod', 'elevage', '/serre', 'n3pp-prod', '/n3pp/api/realtime/system/health', 70],
        ['n3-n3pp-test', 'n3pp', '3', 'Élevage (N3PP test)', 'n3pp_test', 'elevage-test', '/n3pp-test/n3ppdatas/n3pp-data.php', 'n3pp-test', '/n3pp-test/api/realtime/system/health', 80],
        ['n3-energie-test', 'energie', null, 'Énergie (banc test)', 'energie_test', 'energie-test', '/energie-test', 'energie-test', '/energie-test/api/realtime/system/health', 90],
        ['n3-pgl-01', 'pgl', 'poissonglouton', 'Poissonglouton', 'prod', 'pgl', '/pgl', null, null, 100],
    ];

    private bool $schemaEnsured = false;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAll(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM `' . self::TABLE . '`';
        if ($activeOnly) {
            $sql .= ' WHERE active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, label ASC';

        return $this->selectWithSelfHeal($sql);
    }

    /**
     * Appareils avec URL de santé pour les cartes Live.
     *
     * @return list<array<string, mixed>>
     */
    public function findLiveDevices(): array
    {
        $sql = 'SELECT * FROM `' . self::TABLE . '`'
            . ' WHERE active = 1 AND live_module_id IS NOT NULL AND live_health_url IS NOT NULL'
            . ' ORDER BY sort_order ASC, label ASC';

        return $this->selectWithSelfHeal($sql);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByDeviceId(string $deviceId): ?array
    {
        $rows = $this->selectWithSelfHeal(
            'SELECT * FROM `' . self::TABLE . '` WHERE device_id = :id LIMIT 1',
            [':id' => $deviceId]
        );

        return $rows[0] ?? null;
    }

    /**
     * @param array{
     *   device_id: string,
     *   family: string,
     *   board?: string|null,
     *   label: string,
     *   env?: string,
     *   page_key?: string|null,
     *   page_url?: string|null,
     *   live_module_id?: string|null,
     *   live_health_url?: string|null,
     *   active?: bool,
     *   sort_order?: int,
     *   notes?: string|null,
     *   updated_by?: string|null
     * } $data
     */
    public function upsert(array $data): void
    {
        $this->ensureSchema();

        $sql = 'INSERT INTO `' . self::TABLE . '` ('
            . ' device_id, family, board, label, env, page_key, page_url,'
            . ' live_module_id, live_health_url, active, sort_order, notes, updated_by'
            . ') VALUES ('
            . ' :device_id, :family, :board, :label, :env, :page_key, :page_url,'
            . ' :live_module_id, :live_health_url, :active, :sort_order, :notes, :updated_by'
            . ') ON DUPLICATE KEY UPDATE'
            . ' family = VALUES(family), board = VALUES(board), label = VALUES(label),'
            . ' env = VALUES(env), page_key = VALUES(page_key), page_url = VALUES(page_url),'
            . ' live_module_id = VALUES(live_module_id), live_health_url = VALUES(live_health_url),'
            . ' active = VALUES(active), sort_order = VALUES(sort_order),'
            . ' notes = VALUES(notes), updated_by = VALUES(updated_by)';

        $this->execute($sql, [
            ':device_id' => $data['device_id'],
            ':family' => $data['family'],
            ':board' => $data['board'] ?? null,
            ':label' => $data['label'],
            ':env' => $data['env'] ?? 'prod',
            ':page_key' => $data['page_key'] ?? null,
            ':page_url' => $data['page_url'] ?? null,
            ':live_module_id' => $data['live_module_id'] ?? null,
            ':live_health_url' => $data['live_health_url'] ?? null,
            ':active' => ($data['active'] ?? true) ? 1 : 0,
            ':sort_order' => $data['sort_order'] ?? 1000,
            ':notes' => $data['notes'] ?? null,
            ':updated_by' => $data['updated_by'] ?? null,
        ]);
    }

    public function setActive(string $deviceId, bool $active, ?string $user): bool
    {
        $this->ensureSchema();
        $sql = 'UPDATE `' . self::TABLE . '` SET active = :active, updated_by = :user WHERE device_id = :id';

        return $this->execute($sql, [
            ':active' => $active ? 1 : 0,
            ':user' => $user,
            ':id' => $deviceId,
        ]);
    }

    public function delete(string $deviceId): bool
    {
        $this->ensureSchema();

        return $this->execute(
            'DELETE FROM `' . self::TABLE . '` WHERE device_id = :id',
            [':id' => $deviceId]
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function selectWithSelfHeal(string $sql, array $params = []): array
    {
        try {
            return $params === [] ? $this->fetchAll($sql) : $this->fetchAll($sql, $params);
        } catch (\PDOException $e) {
            if (!$this->isMissingTable($e)) {
                throw $e;
            }
            $this->ensureSchema();

            return $params === [] ? $this->fetchAll($sql) : $this->fetchAll($sql, $params);
        }
    }

    private function isMissingTable(\PDOException $e): bool
    {
        return (string) $e->getCode() === '42S02' || str_contains($e->getMessage(), '1146');
    }

    private function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }
        $this->schemaEnsured = true;

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . ' `device_id` VARCHAR(64) NOT NULL,'
            . ' `family` VARCHAR(32) NOT NULL,'
            . ' `board` VARCHAR(64) DEFAULT NULL,'
            . ' `label` VARCHAR(128) NOT NULL,'
            . ' `env` VARCHAR(32) NOT NULL DEFAULT \'prod\','
            . ' `page_key` VARCHAR(64) DEFAULT NULL,'
            . ' `page_url` VARCHAR(255) DEFAULT NULL,'
            . ' `live_module_id` VARCHAR(64) DEFAULT NULL,'
            . ' `live_health_url` VARCHAR(255) DEFAULT NULL,'
            . ' `active` TINYINT(1) NOT NULL DEFAULT 1,'
            . ' `sort_order` INT NOT NULL DEFAULT 1000,'
            . ' `notes` VARCHAR(255) DEFAULT NULL,'
            . ' `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . ' `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . ' `updated_by` VARCHAR(64) DEFAULT NULL,'
            . ' PRIMARY KEY (`device_id`),'
            . ' KEY `idx_family` (`family`),'
            . ' KEY `idx_active_sort` (`active`, `sort_order`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $seedSql = 'INSERT INTO `' . self::TABLE . '`'
            . ' (device_id, family, board, label, env, page_key, page_url, live_module_id, live_health_url, active, sort_order)'
            . ' VALUES (:device_id, :family, :board, :label, :env, :page_key, :page_url, :live_module_id, :live_health_url, 1, :sort_order)'
            . ' ON DUPLICATE KEY UPDATE device_id = device_id';

        foreach (self::SEED as $row) {
            [$deviceId, $family, $board, $label, $env, $pageKey, $pageUrl, $liveId, $liveUrl, $sort] = $row;
            $this->execute($seedSql, [
                ':device_id' => $deviceId,
                ':family' => $family,
                ':board' => $board,
                ':label' => $label,
                ':env' => $env,
                ':page_key' => $pageKey,
                ':page_url' => $pageUrl,
                ':live_module_id' => $liveId,
                ':live_health_url' => $liveUrl,
                ':sort_order' => $sort,
            ]);
        }
    }
}
