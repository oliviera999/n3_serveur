<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\TableConfig;
use PDO;

/**
 * Repository des battements de coeur firmware (uptime, free heap, reboots).
 *
 * La table cible est determinee par l'environnement courant via TableConfig
 * (`ffp3Heartbeat`, `ffp3Heartbeat2`, etc.). La validation est faite via une
 * whitelist stricte, identique a celle utilisee historiquement par HeartbeatController.
 *
 * Heartbeat ENRICHI (6.59.0, firmware ffp5cs ≥ 15.34) : RSSI, version, raison de boot, partition,
 * état OTA de l'image, boots ratés consécutifs, mode sans échec, décalage d'horloge, durée et
 * tentatives de connexion WiFi. Ces colonnes viennent de `migrations/2026_11_contact_diagnostics.sql`
 * et ne sont écrites que si elles existent ({@see columnExists()}) : un serveur non migré continue
 * d'enregistrer le heartbeat historique.
 */
final class HeartbeatRepository extends AbstractRepository
{
    /**
     * Tables autorisees (whitelist) pour les ecritures heartbeat.
     */
    private const ALLOWED_TABLES = [
        'ffp3Heartbeat',
        'ffp3Heartbeat2',
        'ffp3Heartbeat3',
        'ffp3HeartbeatS3',
        'ffp3HeartbeatS3Test',
    ];

    /** Colonnes optionnelles (nom SQL → clé du tableau `$extra`), toutes NULL-ables. */
    public const OPTIONAL_COLUMNS = [
        'rssi' => 'rssi',
        'version' => 'version',
        'resetReason' => 'resetReason',
        'partition' => 'partition',
        'otaState' => 'otaState',
        'failedBoots' => 'failedBoots',
        'safeMode' => 'safeMode',
        'clockOffsetS' => 'clockOffsetS',
        'wifiConnectMs' => 'wifiConnectMs',
        'wifiAttempts' => 'wifiAttempts',
        // 6.60.0 : migrations/2026_11_heartbeat_resources.sql
        'maxBlock' => 'maxBlock',
        'idlePct' => 'idlePct',
        'nvsUsedPct' => 'nvsUsedPct',
    ];

    /** @var array<string, bool> Cache colonnes par worker. */
    private static array $columnExistsCache = [];

    /**
     * Insere un battement de coeur dans la table correspondant a l'environnement courant.
     *
     * @param array<string, scalar|null> $extra Champs enrichis (clés de {@see OPTIONAL_COLUMNS}), ignorés
     *                                          si la colonne n'existe pas encore
     *
     * @throws \RuntimeException si la table issue de l'env n'est pas dans la whitelist.
     */
    public function insert(int $uptime, int $freeHeap, int $minHeap, int $reboots, array $extra = []): void
    {
        $table = TableConfig::getHeartbeatTable();
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new \RuntimeException("Table heartbeat invalide: {$table}");
        }

        $columns = ['uptime', 'freeHeap', 'minHeap', 'reboots'];
        $placeholders = [':uptime', ':free', ':min', ':reboots'];
        $params = [
            ':uptime' => $uptime,
            ':free' => $freeHeap,
            ':min' => $minHeap,
            ':reboots' => $reboots,
        ];
        foreach (self::OPTIONAL_COLUMNS as $column => $key) {
            if (!array_key_exists($key, $extra) || $extra[$key] === null || $extra[$key] === '') {
                continue;
            }
            if (!$this->columnExists($table, $column)) {
                continue;
            }
            $columns[] = "`{$column}`";
            $placeholders[] = ':' . $key;
            $params[':' . $key] = $extra[$key];
        }

        $this->execute(
            "INSERT INTO `{$table}` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $params
        );
    }

    /**
     * Présence d'une colonne (INFORMATION_SCHEMA en MySQL/MariaDB, PRAGMA en SQLite), mise en cache.
     */
    public function columnExists(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        if (isset(self::$columnExistsCache[$key])) {
            return self::$columnExistsCache[$key];
        }
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $exists = false;
                foreach ($this->fetchAll("PRAGMA table_info(`{$table}`)") as $row) {
                    if (($row['name'] ?? '') === $column) {
                        $exists = true;
                        break;
                    }
                }
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
                );
                $stmt->execute([':t' => $table, ':c' => $column]);
                $exists = $stmt->fetch() !== false;
            }
        } catch (\Throwable) {
            $exists = false;
        }
        self::$columnExistsCache[$key] = $exists;

        return $exists;
    }

    /** Vide le cache de colonnes (tests). */
    public static function resetColumnCache(): void
    {
        self::$columnExistsCache = [];
    }
}
