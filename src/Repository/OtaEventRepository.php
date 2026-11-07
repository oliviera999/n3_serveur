<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Journal `ota_events` : chaque requête servie par {@see \App\Controller\Ffp3\OtaFileController}
 * (`metadata.json` comme `firmware.bin`), avec l'IP, le canal, l'en-tête Range, le statut HTTP,
 * les octets servis face à la taille du fichier, la durée et un indicateur « transfert complet ».
 *
 * Incident du 06/11/2026 : impossible de prouver qu'un module avait téléchargé la 15.33 publiée
 * le même après-midi. Désormais une perte de contact se lit face à ces lignes.
 *
 * Table globale, créée par `migrations/2026_11_contact_diagnostics.sql` ou à la première
 * écriture ({@see ensureSchema()}, additif). Lectures bornées.
 */
class OtaEventRepository extends AbstractRepository
{
    public const TABLE = 'ota_events';

    private bool $schemaEnsured = false;

    /**
     * @param array{
     *   ip?: ?string, path: string, channel: string, kind: string, board?: ?string, firmware_version?: ?string,
     *   range_header?: ?string, http_status: int, bytes_sent?: int, file_size?: ?int, duration_ms?: ?int,
     *   complete?: bool, note?: ?string, occurred_at?: ?string
     * } $event
     */
    public function record(array $event): void
    {
        $sql = 'INSERT INTO `' . self::TABLE . '` (occurred_at, ip, path, channel, kind, board, firmware_version, range_header,'
            . ' http_status, bytes_sent, file_size, duration_ms, complete, note)'
            . ' VALUES (:at, :ip, :path, :channel, :kind, :board, :version, :range, :status, :bytes, :size, :duration, :complete, :note)';
        $params = [
            ':at' => $event['occurred_at'] ?? date('Y-m-d H:i:s'),
            ':ip' => isset($event['ip']) ? substr((string) $event['ip'], 0, 45) : null,
            ':path' => substr($event['path'], 0, 190),
            ':channel' => substr($event['channel'], 0, 40),
            ':kind' => substr($event['kind'], 0, 10),
            ':board' => isset($event['board']) && $event['board'] !== '' ? substr((string) $event['board'], 0, 64) : null,
            ':version' => isset($event['firmware_version']) && $event['firmware_version'] !== '' ? substr((string) $event['firmware_version'], 0, 30) : null,
            ':range' => isset($event['range_header']) && $event['range_header'] !== '' ? substr((string) $event['range_header'], 0, 64) : null,
            ':status' => $event['http_status'],
            ':bytes' => $event['bytes_sent'] ?? 0,
            ':size' => $event['file_size'] ?? null,
            ':duration' => $event['duration_ms'] ?? null,
            ':complete' => ($event['complete'] ?? false) ? 1 : 0,
            ':note' => isset($event['note']) && $event['note'] !== '' ? substr((string) $event['note'], 0, 120) : null,
        ];
        try {
            $this->execute($sql, $params);
        } catch (\PDOException $e) {
            if ($this->schemaEnsured || !DeviceContactRepository::isMissingTable($e)) {
                throw $e;
            }
            $this->ensureSchema();
            $this->execute($sql, $params);
        }
    }

    /**
     * Événements OTA des canaux donnés sur une fenêtre (du plus ancien au plus récent).
     *
     * @param list<string> $channels
     * @return list<array<string, mixed>>
     */
    public function between(array $channels, string $from, string $to, int $limit = 500): array
    {
        if ($channels === []) {
            return [];
        }
        $limit = max(1, min(5000, $limit));
        $placeholders = [];
        $params = [':from' => $from, ':to' => $to];
        foreach (array_values($channels) as $i => $channel) {
            $placeholders[] = ":c{$i}";
            $params[":c{$i}"] = $channel;
        }
        try {
            return $this->fetchAll(
                'SELECT id, occurred_at, ip, path, channel, kind, board, firmware_version, range_header, http_status, bytes_sent,'
                . ' file_size, duration_ms, complete, note FROM `' . self::TABLE . '`'
                . ' WHERE channel IN (' . implode(',', $placeholders) . ') AND occurred_at BETWEEN :from AND :to'
                . " ORDER BY occurred_at ASC, id ASC LIMIT {$limit}",
                $params
            );
        } catch (\PDOException $e) {
            if (DeviceContactRepository::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Dernier téléchargement COMPLET d'un binaire sur l'un des canaux (epoch), ou null.
     *
     * @param list<string> $channels
     * @return array{occurred_at: string, channel: string, ip: ?string, board: ?string, bytes_sent: int}|null
     */
    public function lastCompleteBinaryDownload(array $channels, ?string $since = null): ?array
    {
        if ($channels === []) {
            return null;
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($channels) as $i => $channel) {
            $placeholders[] = ":c{$i}";
            $params[":c{$i}"] = $channel;
        }
        $sql = 'SELECT occurred_at, channel, ip, board, bytes_sent FROM `' . self::TABLE . '`'
            . ' WHERE kind = \'bin\' AND complete = 1 AND channel IN (' . implode(',', $placeholders) . ')';
        if ($since !== null) {
            $sql .= ' AND occurred_at >= :since';
            $params[':since'] = $since;
        }
        $sql .= ' ORDER BY occurred_at DESC, id DESC LIMIT 1';
        try {
            $row = $this->fetchOne($sql, $params);
        } catch (\PDOException $e) {
            if (DeviceContactRepository::isMissingTable($e)) {
                return null;
            }
            throw $e;
        }
        if ($row === null) {
            return null;
        }

        return [
            'occurred_at' => (string) $row['occurred_at'],
            'channel' => (string) $row['channel'],
            'ip' => isset($row['ip']) ? (string) $row['ip'] : null,
            'board' => isset($row['board']) ? (string) $row['board'] : null,
            'bytes_sent' => (int) $row['bytes_sent'],
        ];
    }

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }
        $this->schemaEnsured = true;
        $isSqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $this->pdo->exec($isSqlite
            ? 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (id INTEGER PRIMARY KEY AUTOINCREMENT, occurred_at TEXT NOT NULL, ip TEXT,'
                . ' path TEXT NOT NULL, channel TEXT NOT NULL, kind TEXT NOT NULL, board TEXT, firmware_version TEXT, range_header TEXT,'
                . ' http_status INTEGER NOT NULL, bytes_sent INTEGER NOT NULL DEFAULT 0, file_size INTEGER, duration_ms INTEGER,'
                . ' complete INTEGER NOT NULL DEFAULT 0, note TEXT)'
            : 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
                . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `occurred_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . ' `ip` VARCHAR(45) DEFAULT NULL, `path` VARCHAR(190) NOT NULL, `channel` VARCHAR(40) NOT NULL, `kind` VARCHAR(10) NOT NULL,'
                . ' `board` VARCHAR(64) DEFAULT NULL, `firmware_version` VARCHAR(30) DEFAULT NULL, `range_header` VARCHAR(64) DEFAULT NULL,'
                . ' `http_status` SMALLINT UNSIGNED NOT NULL, `bytes_sent` BIGINT UNSIGNED NOT NULL DEFAULT 0, `file_size` BIGINT UNSIGNED DEFAULT NULL,'
                . ' `duration_ms` INT UNSIGNED DEFAULT NULL, `complete` TINYINT(1) NOT NULL DEFAULT 0, `note` VARCHAR(120) DEFAULT NULL,'
                . ' PRIMARY KEY (`id`), KEY `idx_ota_events_time` (`occurred_at`), KEY `idx_ota_events_channel` (`channel`, `occurred_at`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
