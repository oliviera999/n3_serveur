<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Réglages serveur globaux (table `serverSettings`), pilotables depuis la
 * supervision sans redéploiement (.env reste le repli par défaut).
 */
class ServerSettingsRepository extends AbstractRepository
{
    public const KEY_HMAC_AUDIT_ENABLED = 'hmac_audit_enabled';

    public const KEY_HMAC_STRICT_MODE = 'hmac_strict_mode';

    public const KEY_HMAC_NONCE_REQUIRED = 'hmac_nonce_required';

    private const TABLE = 'serverSettings';

    private bool $schemaEnsured = false;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $raw = $this->getRaw($key);
        if ($raw === null) {
            return $default;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'on', 'yes'], true);
    }

    public function getString(string $key, string $default = ''): string
    {
        $raw = $this->getRaw($key);

        return $raw === null ? $default : $raw;
    }

    public function getRaw(string $key): ?string
    {
        $this->ensureSchema();

        try {
            $row = $this->fetchOne(
                'SELECT setting_value FROM `' . self::TABLE . '` WHERE setting_key = :key LIMIT 1',
                [':key' => $key]
            );
        } catch (\PDOException $e) {
            if ($this->isMissingTable($e)) {
                return null;
            }
            throw $e;
        }

        if ($row === null) {
            return null;
        }

        return (string) $row['setting_value'];
    }

    public function setString(string $key, string $value, ?string $updatedBy): void
    {
        $this->setRaw($key, $value, $updatedBy);
    }

    public function setRaw(string $key, string $value, ?string $updatedBy): void
    {
        $this->ensureSchema();

        $sql = 'INSERT INTO `' . self::TABLE . '` (setting_key, setting_value, updated_by)'
            . ' VALUES (:key, :value, :user)'
            . ' ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)';

        $this->execute($sql, [
            ':key' => $key,
            ':value' => $value,
            ':user' => $updatedBy,
        ]);
    }

    public function setBool(string $key, bool $value, ?string $updatedBy): void
    {
        $this->setRaw($key, $value ? '1' : '0', $updatedBy);
    }

    public function hasKey(string $key): bool
    {
        $this->ensureSchema();

        try {
            $row = $this->fetchOne(
                'SELECT 1 FROM `' . self::TABLE . '` WHERE setting_key = :key LIMIT 1',
                [':key' => $key]
            );
        } catch (\PDOException $e) {
            if ($this->isMissingTable($e)) {
                return false;
            }
            throw $e;
        }

        return $row !== null;
    }

    public function deleteKey(string $key): void
    {
        $this->ensureSchema();
        $this->execute('DELETE FROM `' . self::TABLE . '` WHERE setting_key = :key', [':key' => $key]);
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
            . ' `setting_key` VARCHAR(64) NOT NULL,'
            . ' `setting_value` VARCHAR(255) NOT NULL,'
            . ' `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . ' `updated_by` VARCHAR(64) DEFAULT NULL,'
            . ' PRIMARY KEY (`setting_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
