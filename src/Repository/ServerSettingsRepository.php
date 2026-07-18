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

    /**
     * Cache mémoire request-scoped de l'intégralité de la table (clé → valeur).
     *
     * Renseigné en un seul `SELECT` au premier accès puis servant hasKey/getRaw/
     * getBool sans nouvelle requête. Ces réglages sont lus plusieurs fois par
     * requête (OperationalSettingsService résout chaque clé via hasKey + getRaw)
     * pour un jeu de lignes minuscule et quasi statique : le cache supprime la
     * multiplication de petits SELECT. Invalidé (mis à jour) sur chaque write.
     *
     * @var array<string, string>|null null = pas encore chargé
     */
    private ?array $cache = null;

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
        $cache = $this->loadCache();

        return $cache[$key] ?? null;
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

        // Le cache mémoire reflète immédiatement l'écriture (cohérence intra-requête).
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    public function setBool(string $key, bool $value, ?string $updatedBy): void
    {
        $this->setRaw($key, $value ? '1' : '0', $updatedBy);
    }

    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->loadCache());
    }

    public function deleteKey(string $key): void
    {
        $this->ensureSchema();
        $this->execute('DELETE FROM `' . self::TABLE . '` WHERE setting_key = :key', [':key' => $key]);

        // Invalide l'entrée en mémoire pour rester cohérent avec la BDD.
        if ($this->cache !== null) {
            unset($this->cache[$key]);
        }
    }

    /**
     * Charge (une seule fois par instance) l'intégralité de la table dans le
     * cache mémoire. Un unique `SELECT setting_key, setting_value` remplace la
     * multiplication de SELECT unitaires (hasKey + getRaw par clé). En cas de
     * table absente (SQLite/tests, prod non migrée), le cache reste vide.
     *
     * @return array<string, string>
     */
    private function loadCache(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->ensureSchema();

        try {
            $rows = $this->fetchAll(
                'SELECT setting_key, setting_value FROM `' . self::TABLE . '`'
            );
        } catch (\PDOException $e) {
            if ($this->isMissingTable($e)) {
                return $this->cache = [];
            }
            throw $e;
        }

        $cache = [];
        foreach ($rows as $row) {
            $cache[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $this->cache = $cache;
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

        // DDL adapté au driver PDO : MySQL/MariaDB en prod, mais le suite Unit
        // s'exécute sur SQLite qui ne parse ni `ON UPDATE CURRENT_TIMESTAMP` ni
        // la clause `ENGINE=… CHARSET=… COLLATE=…`. On émet alors une DDL portable
        // (même colonnes / clé primaire) pour rester résolvable hors MySQL.
        $isMysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        $updatedAt = $isMysql
            ? '`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            : '`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,';
        $tableOptions = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . ' `setting_key` VARCHAR(64) NOT NULL,'
            . ' `setting_value` VARCHAR(255) NOT NULL,'
            . ' ' . $updatedAt
            . ' `updated_by` VARCHAR(64) DEFAULT NULL,'
            . ' PRIMARY KEY (`setting_key`)'
            . ')' . $tableOptions
        );
    }
}
