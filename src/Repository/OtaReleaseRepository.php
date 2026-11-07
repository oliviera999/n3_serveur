<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Table `ota_releases` : publications OTA détectées (changement d'un `metadata.json`) —
 * cible, canal, carte, version, empreinte, taille, horodatage de publication, auteur.
 * Alimentée par {@see \App\Service\OtaReleaseSyncService} (CRON) ; l'horodatage retenu est la
 * date de modification du manifeste au moment de la détection (le CRON passe chaque minute).
 */
class OtaReleaseRepository extends AbstractRepository
{
    public const TABLE = 'ota_releases';

    private bool $schemaEnsured = false;

    /**
     * Insère la publication si elle est inconnue (clé : cible / canal / carte / version / empreinte).
     *
     * @param array{target: string, channel: string, board: string, version: string, hash?: ?string, size?: ?int, bin_url?: ?string, published_at: string, author?: ?string, source?: string} $release
     * @return bool Vrai si une ligne a été AJOUTÉE (nouvelle publication).
     */
    public function recordIfNew(array $release): bool
    {
        if ($this->exists($release)) {
            return false;
        }
        $sql = 'INSERT INTO `' . self::TABLE . '` (target, channel, board, version, hash, size, bin_url, published_at, detected_at, author, source)'
            . ' VALUES (:target, :channel, :board, :version, :hash, :size, :url, :published, :detected, :author, :source)';
        $params = [
            ':target' => substr($release['target'], 0, 40),
            ':channel' => substr($release['channel'], 0, 40),
            ':board' => substr($release['board'], 0, 40),
            ':version' => substr($release['version'], 0, 30),
            ':hash' => isset($release['hash']) ? substr((string) $release['hash'], 0, 64) : null,
            ':size' => $release['size'] ?? null,
            ':url' => isset($release['bin_url']) ? substr((string) $release['bin_url'], 0, 255) : null,
            ':published' => $release['published_at'],
            ':detected' => date('Y-m-d H:i:s'),
            ':author' => isset($release['author']) ? substr((string) $release['author'], 0, 120) : null,
            ':source' => substr($release['source'] ?? 'cron', 0, 20),
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

        return true;
    }

    /**
     * @param array{target: string, channel: string, board: string, version: string, hash?: ?string} $release
     */
    public function exists(array $release): bool
    {
        try {
            $count = $this->fetchScalar(
                'SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE target = :target AND channel = :channel AND board = :board'
                . ' AND version = :version AND ((hash IS NULL AND :hash1 IS NULL) OR hash = :hash2)',
                [
                    ':target' => $release['target'],
                    ':channel' => $release['channel'],
                    ':board' => $release['board'],
                    ':version' => $release['version'],
                    ':hash1' => $release['hash'] ?? null,
                    ':hash2' => $release['hash'] ?? null,
                ]
            );
        } catch (\PDOException $e) {
            if (DeviceContactRepository::isMissingTable($e)) {
                return false;
            }
            throw $e;
        }

        return (int) $count > 0;
    }

    /**
     * Publications d'une cible sur une fenêtre (du plus ancien au plus récent).
     *
     * @param list<string> $targets
     * @return list<array<string, mixed>>
     */
    public function between(array $targets, string $from, string $to, int $limit = 100): array
    {
        if ($targets === []) {
            return [];
        }
        $limit = max(1, min(1000, $limit));
        $placeholders = [];
        $params = [':from' => $from, ':to' => $to];
        foreach (array_values($targets) as $i => $t) {
            $placeholders[] = ":t{$i}";
            $params[":t{$i}"] = $t;
        }
        try {
            return $this->fetchAll(
                'SELECT id, target, channel, board, version, hash, size, bin_url, published_at, detected_at, author, source'
                . ' FROM `' . self::TABLE . '` WHERE target IN (' . implode(',', $placeholders) . ') AND published_at BETWEEN :from AND :to'
                . " ORDER BY published_at ASC, id ASC LIMIT {$limit}",
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
     * Dernière publication connue d'une cible / d'un canal / d'une carte (toutes dates).
     *
     * @return array<string, mixed>|null
     */
    public function latest(string $target, ?string $channel = null, ?string $board = null): ?array
    {
        $sql = 'SELECT id, target, channel, board, version, hash, size, bin_url, published_at, detected_at, author, source'
            . ' FROM `' . self::TABLE . '` WHERE target = :target';
        $params = [':target' => $target];
        if ($channel !== null) {
            $sql .= ' AND channel = :channel';
            $params[':channel'] = $channel;
        }
        if ($board !== null) {
            $sql .= ' AND board = :board';
            $params[':board'] = $board;
        }
        $sql .= ' ORDER BY published_at DESC, id DESC LIMIT 1';
        try {
            return $this->fetchOne($sql, $params);
        } catch (\PDOException $e) {
            if (DeviceContactRepository::isMissingTable($e)) {
                return null;
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
            ? 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (id INTEGER PRIMARY KEY AUTOINCREMENT, target TEXT NOT NULL, channel TEXT NOT NULL,'
                . ' board TEXT NOT NULL, version TEXT NOT NULL, hash TEXT, size INTEGER, bin_url TEXT, published_at TEXT NOT NULL,'
                . ' detected_at TEXT NOT NULL, author TEXT, source TEXT NOT NULL DEFAULT \'cron\')'
            : 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
                . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `target` VARCHAR(40) NOT NULL, `channel` VARCHAR(40) NOT NULL,'
                . ' `board` VARCHAR(40) NOT NULL, `version` VARCHAR(30) NOT NULL, `hash` VARCHAR(64) DEFAULT NULL, `size` BIGINT UNSIGNED DEFAULT NULL,'
                . ' `bin_url` VARCHAR(255) DEFAULT NULL, `published_at` DATETIME NOT NULL, `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . ' `author` VARCHAR(120) DEFAULT NULL, `source` VARCHAR(20) NOT NULL DEFAULT \'cron\','
                . ' PRIMARY KEY (`id`), UNIQUE KEY `uq_ota_release` (`target`, `channel`, `board`, `version`, `hash`), KEY `idx_ota_releases_time` (`published_at`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
