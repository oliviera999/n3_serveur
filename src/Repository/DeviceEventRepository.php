<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Table `device_events` : événements rapportés PAR LE FIRMWARE — rapport OTA (`/ota-report` :
 * check, start, progress, done, fail, rollback, marked_valid), journal de bord vidé au heartbeat
 * (échecs WiFi, DNS/TLS, codes HTTP, NTP, veille, panic) et informations de boot.
 *
 * Dédoublonnage par numéro de séquence firmware (clé unique famille/env/carte/source/seq) : un
 * événement persisté en NVS puis renvoyé après reboot n'est compté qu'une fois ; le serveur
 * acquitte les `seq` reçus pour que le firmware purge son anneau.
 */
class DeviceEventRepository extends AbstractRepository
{
    public const TABLE = 'device_events';

    public const SOURCE_OTA_REPORT = 'ota_report';
    public const SOURCE_HEARTBEAT_LOG = 'heartbeat_log';
    public const SOURCE_BOOT = 'boot';

    private bool $schemaEnsured = false;

    /**
     * @param array{
     *   family: string, env: string, board?: ?string, firmware_version?: ?string, source: string, seq?: ?int,
     *   device_ts?: ?int, uptime_s?: ?int, event: string, detail?: ?string, received_at?: ?string
     * } $event
     * @return bool Vrai si inséré, faux si doublon (même seq déjà reçu)
     */
    public function record(array $event): bool
    {
        $sql = 'INSERT INTO `' . self::TABLE . '` (received_at, family, env, board, firmware_version, source, seq, device_ts, uptime_s, event, detail)'
            . ' VALUES (:at, :family, :env, :board, :version, :source, :seq, :device_ts, :uptime, :event, :detail)';
        $params = [
            ':at' => $event['received_at'] ?? date('Y-m-d H:i:s'),
            ':family' => substr(strtolower($event['family']), 0, 8),
            ':env' => substr($event['env'], 0, 16),
            ':board' => isset($event['board']) && $event['board'] !== '' ? substr((string) $event['board'], 0, 64) : null,
            ':version' => isset($event['firmware_version']) && $event['firmware_version'] !== '' ? substr((string) $event['firmware_version'], 0, 30) : null,
            ':source' => substr($event['source'], 0, 16),
            ':seq' => $event['seq'] ?? null,
            ':device_ts' => $event['device_ts'] ?? null,
            ':uptime' => $event['uptime_s'] ?? null,
            ':event' => substr($event['event'], 0, 32),
            ':detail' => isset($event['detail']) && $event['detail'] !== '' ? substr((string) $event['detail'], 0, 255) : null,
        ];
        try {
            $this->execute($sql, $params);
        } catch (\PDOException $e) {
            if (self::isDuplicate($e)) {
                return false;
            }
            if ($this->schemaEnsured || !DeviceContactRepository::isMissingTable($e)) {
                throw $e;
            }
            $this->ensureSchema();
            try {
                $this->execute($sql, $params);
            } catch (\PDOException $e2) {
                if (self::isDuplicate($e2)) {
                    return false;
                }
                throw $e2;
            }
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function between(string $family, string $env, string $from, string $to, int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        try {
            return $this->fetchAll(
                'SELECT id, received_at, board, firmware_version, source, seq, device_ts, uptime_s, event, detail FROM `' . self::TABLE . '`'
                . ' WHERE family = :family AND env = :env AND received_at BETWEEN :from AND :to'
                . " ORDER BY received_at ASC, id ASC LIMIT {$limit}",
                [':family' => strtolower($family), ':env' => $env, ':from' => $from, ':to' => $to]
            );
        } catch (\PDOException $e) {
            if (DeviceContactRepository::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }
        $this->schemaEnsured = true;
        $isSqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $this->pdo->exec($isSqlite
            ? 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (id INTEGER PRIMARY KEY AUTOINCREMENT, received_at TEXT NOT NULL,'
                . ' family TEXT NOT NULL, env TEXT NOT NULL, board TEXT, firmware_version TEXT, source TEXT NOT NULL, seq INTEGER,'
                . ' device_ts INTEGER, uptime_s INTEGER, event TEXT NOT NULL, detail TEXT, UNIQUE (family, env, board, source, seq))'
            : 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
                . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . ' `family` VARCHAR(8) NOT NULL, `env` VARCHAR(16) NOT NULL, `board` VARCHAR(64) DEFAULT NULL, `firmware_version` VARCHAR(30) DEFAULT NULL,'
                . ' `source` VARCHAR(16) NOT NULL, `seq` INT UNSIGNED DEFAULT NULL, `device_ts` BIGINT DEFAULT NULL, `uptime_s` INT UNSIGNED DEFAULT NULL,'
                . ' `event` VARCHAR(32) NOT NULL, `detail` VARCHAR(255) DEFAULT NULL,'
                . ' PRIMARY KEY (`id`), UNIQUE KEY `uq_device_event_seq` (`family`, `env`, `board`, `source`, `seq`),'
                . ' KEY `idx_device_events_time` (`family`, `env`, `received_at`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private static function isDuplicate(\PDOException $e): bool
    {
        $message = $e->getMessage();

        return $e->getCode() === '23000' || str_contains($message, 'Duplicate entry') || str_contains($message, 'UNIQUE constraint failed');
    }
}
