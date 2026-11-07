<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Journal `device_contacts` : UNE ligne par requête d'un module (POST de mesures, heartbeat,
 * rapport OTA), qu'elle soit acceptée ou rejetée, avec le motif de rejet, le mode
 * d'authentification et le décalage d'horloge mesuré (`ts_received − heure serveur`).
 *
 * Table GLOBALE (toutes familles, tous environnements : colonnes `family` + `env`), créée par
 * `migrations/2026_11_contact_diagnostics.sql` ou à la volée à la première écriture
 * ({@see ensureSchema()}, `CREATE TABLE IF NOT EXISTS` — additif, même pattern que
 * {@see IotDeviceRepository}). Aucune donnée personnelle : pas de corps de requête, pas d'e-mail.
 *
 * Lectures bornées (`LIMIT`) : la table grossit d'environ 400 lignes / jour / module.
 */
class DeviceContactRepository extends AbstractRepository
{
    public const TABLE = 'device_contacts';

    public const KIND_POST = 'post';
    public const KIND_HEARTBEAT = 'heartbeat';
    public const KIND_OTA_REPORT = 'ota_report';

    /** Modes d'authentification réellement vérifiés par le serveur (contact « authentifié »). */
    public const AUTHENTICATED_MODES = ['x_sig_body', 'legacy_timestamp', 'legacy_nonce', 'api_key'];

    private bool $schemaEnsured = false;

    /**
     * @param array{
     *   family: string, env: string, kind: string, accepted: bool, http_status: int,
     *   auth_mode?: ?string, reject_reason?: ?string, ts_received?: ?int, clock_offset_s?: ?int,
     *   firmware_version?: ?string, post_id?: ?string, ip?: ?string, received_at?: ?string
     * } $contact
     */
    public function record(array $contact): void
    {
        $sql = 'INSERT INTO `' . self::TABLE . '` (received_at, family, env, kind, accepted, http_status, auth_mode, reject_reason,'
            . ' ts_received, clock_offset_s, firmware_version, post_id, ip)'
            . ' VALUES (:received_at, :family, :env, :kind, :accepted, :status, :auth, :reason, :ts, :offset, :version, :post_id, :ip)';
        $params = [
            ':received_at' => $contact['received_at'] ?? date('Y-m-d H:i:s'),
            ':family' => substr(strtolower($contact['family']), 0, 8),
            ':env' => substr($contact['env'], 0, 16),
            ':kind' => substr($contact['kind'], 0, 12),
            ':accepted' => $contact['accepted'] ? 1 : 0,
            ':status' => $contact['http_status'],
            ':auth' => isset($contact['auth_mode']) ? substr((string) $contact['auth_mode'], 0, 24) : null,
            ':reason' => isset($contact['reject_reason']) ? substr((string) $contact['reject_reason'], 0, 64) : null,
            ':ts' => $contact['ts_received'] ?? null,
            ':offset' => $contact['clock_offset_s'] ?? null,
            ':version' => isset($contact['firmware_version']) ? substr((string) $contact['firmware_version'], 0, 30) : null,
            ':post_id' => isset($contact['post_id']) ? substr((string) $contact['post_id'], 0, 64) : null,
            ':ip' => isset($contact['ip']) ? substr((string) $contact['ip'], 0, 45) : null,
        ];

        try {
            $this->execute($sql, $params);
        } catch (\PDOException $e) {
            if ($this->schemaEnsured || !self::isMissingTable($e)) {
                throw $e;
            }
            $this->ensureSchema();
            $this->execute($sql, $params);
        }
    }

    /**
     * Dernier contact ACCEPTÉ d'une famille (epoch), toutes requêtes ou seulement celles dont
     * l'authentification a été vérifiée (HMAC ou clé API — pas le CRC seul du heartbeat FFP3 legacy).
     */
    public function lastAcceptedContact(string $family, bool $authenticatedOnly = true, ?string $env = null): ?int
    {
        $sql = 'SELECT MAX(received_at) FROM `' . self::TABLE . '` WHERE family = :family AND accepted = 1';
        $params = [':family' => strtolower($family)];
        if ($env !== null) {
            $sql .= ' AND env = :env';
            $params[':env'] = $env;
        }
        if ($authenticatedOnly) {
            $sql .= ' AND auth_mode IN (' . implode(',', array_map(static fn (string $m): string => "'{$m}'", self::AUTHENTICATED_MODES)) . ')';
        }
        $value = $this->fetchScalar($sql, $params);
        if ($value === null) {
            return null;
        }
        $ts = strtotime((string) $value);

        return $ts === false ? null : $ts;
    }

    /**
     * Contacts d'une famille / d'un environnement sur une fenêtre, du plus ancien au plus récent.
     *
     * @return list<array<string, mixed>>
     */
    public function between(string $family, string $env, string $from, string $to, int $limit = 2000): array
    {
        $limit = max(1, min(10000, $limit));

        return $this->fetchAll(
            'SELECT id, received_at, kind, accepted, http_status, auth_mode, reject_reason, ts_received, clock_offset_s,'
            . ' firmware_version, post_id, ip FROM `' . self::TABLE . '`'
            . ' WHERE family = :family AND env = :env AND received_at BETWEEN :from AND :to'
            . " ORDER BY received_at ASC, id ASC LIMIT {$limit}",
            [':family' => strtolower($family), ':env' => $env, ':from' => $from, ':to' => $to]
        );
    }

    /**
     * Derniers contacts (plus récent d'abord) porteurs d'un horodatage firmware, pour l'alerte
     * de dérive d'horloge ({@see \App\Service\ClockDriftAlertService}).
     *
     * @return list<array{received_at: string, clock_offset_s: int, accepted: int, kind: string}>
     */
    public function recentClockOffsets(string $family, string $env, int $limit = 200): array
    {
        $limit = max(1, min(2000, $limit));
        $rows = $this->fetchAll(
            'SELECT received_at, clock_offset_s, accepted, kind FROM `' . self::TABLE . '`'
            . ' WHERE family = :family AND env = :env AND clock_offset_s IS NOT NULL'
            . " ORDER BY received_at DESC, id DESC LIMIT {$limit}",
            [':family' => strtolower($family), ':env' => $env]
        );

        return array_values(array_map(static fn (array $r): array => [
            'received_at' => (string) $r['received_at'],
            'clock_offset_s' => (int) $r['clock_offset_s'],
            'accepted' => (int) $r['accepted'],
            'kind' => (string) $r['kind'],
        ], $rows));
    }

    /** Crée la table si elle n'existe pas (additif ; idempotent). */
    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }
        $this->schemaEnsured = true;
        $isSqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $sql = $isSqlite
            ? 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (id INTEGER PRIMARY KEY AUTOINCREMENT, received_at TEXT NOT NULL,'
                . ' family TEXT NOT NULL, env TEXT NOT NULL, kind TEXT NOT NULL, accepted INTEGER NOT NULL, http_status INTEGER NOT NULL,'
                . ' auth_mode TEXT, reject_reason TEXT, ts_received INTEGER, clock_offset_s INTEGER, firmware_version TEXT, post_id TEXT, ip TEXT)'
            : 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
                . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . ' `family` VARCHAR(8) NOT NULL, `env` VARCHAR(16) NOT NULL, `kind` VARCHAR(12) NOT NULL,'
                . ' `accepted` TINYINT(1) NOT NULL, `http_status` SMALLINT UNSIGNED NOT NULL,'
                . ' `auth_mode` VARCHAR(24) DEFAULT NULL, `reject_reason` VARCHAR(64) DEFAULT NULL,'
                . ' `ts_received` BIGINT DEFAULT NULL, `clock_offset_s` INT DEFAULT NULL,'
                . ' `firmware_version` VARCHAR(30) DEFAULT NULL, `post_id` VARCHAR(64) DEFAULT NULL, `ip` VARCHAR(45) DEFAULT NULL,'
                . ' PRIMARY KEY (`id`), KEY `idx_dc_family_time` (`family`, `env`, `received_at`), KEY `idx_dc_time` (`received_at`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->pdo->exec($sql);
    }

    public static function isMissingTable(\PDOException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, "doesn't exist") || str_contains($message, 'no such table') || ($e->getCode() === '42S02');
    }
}
