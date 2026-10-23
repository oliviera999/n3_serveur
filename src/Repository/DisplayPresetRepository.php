<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Presets d'affichage des pages de suivi (table GLOBALE `displayPresets`).
 *
 * Partagés par prod et test d'une même famille (mêmes colonnes) : pas de
 * TableConfig. Le schéma est créé à la volée si absent (MySQL ou SQLite).
 */
class DisplayPresetRepository extends AbstractRepository
{
    private const TABLE = 'displayPresets';

    private bool $schemaEnsured = false;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }

    /**
     * @return list<array{slug: string, label: string, description: string, layout_json: string, is_default: bool, show_on_page: bool, sort_order: int, updated_at: ?string, updated_by: ?string}>
     */
    public function findByPage(string $pageKey): array
    {
        $this->ensureSchema();
        $rows = $this->fetchAll(
            'SELECT slug, label, description, layout_json, is_default, show_on_page, sort_order, updated_at, updated_by'
            . ' FROM `' . self::TABLE . '` WHERE page_key = :page ORDER BY sort_order ASC, label ASC',
            [':page' => $pageKey]
        );

        return array_map(static fn (array $row): array => [
            'slug' => (string) $row['slug'],
            'label' => (string) $row['label'],
            'description' => (string) ($row['description'] ?? ''),
            'layout_json' => (string) $row['layout_json'],
            'is_default' => (int) $row['is_default'] === 1,
            'show_on_page' => (int) $row['show_on_page'] === 1,
            'sort_order' => (int) $row['sort_order'],
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            'updated_by' => $row['updated_by'] !== null ? (string) $row['updated_by'] : null,
        ], $rows);
    }

    /**
     * Crée ou remplace un preset. Si `isDefault`, les autres presets de la page
     * perdent ce statut (un seul défaut par page).
     */
    public function save(
        string $pageKey,
        string $slug,
        string $label,
        string $description,
        string $layoutJson,
        bool $isDefault,
        bool $showOnPage,
        int $sortOrder,
        ?string $updatedBy
    ): void {
        $this->ensureSchema();

        $this->executeInTransaction(function () use ($pageKey, $slug, $label, $description, $layoutJson, $isDefault, $showOnPage, $sortOrder, $updatedBy): void {
            if ($isDefault) {
                $this->execute(
                    'UPDATE `' . self::TABLE . '` SET is_default = 0 WHERE page_key = :page AND slug <> :slug',
                    [':page' => $pageKey, ':slug' => $slug]
                );
            }

            $params = [
                ':page' => $pageKey,
                ':slug' => $slug,
                ':label' => $label,
                ':description' => $description,
                ':layout' => $layoutJson,
                ':is_default' => $isDefault ? 1 : 0,
                ':show_on_page' => $showOnPage ? 1 : 0,
                ':sort_order' => $sortOrder,
                ':user' => $updatedBy,
            ];

            $updated = $this->executeWithRowCount(
                'UPDATE `' . self::TABLE . '` SET label = :label, description = :description, layout_json = :layout,'
                . ' is_default = :is_default, show_on_page = :show_on_page, sort_order = :sort_order,'
                . ' updated_by = :user, updated_at = CURRENT_TIMESTAMP'
                . ' WHERE page_key = :page AND slug = :slug',
                $params
            );
            if ($updated === 0 && !$this->exists($pageKey, $slug)) {
                $this->execute(
                    'INSERT INTO `' . self::TABLE . '`'
                    . ' (page_key, slug, label, description, layout_json, is_default, show_on_page, sort_order, updated_by)'
                    . ' VALUES (:page, :slug, :label, :description, :layout, :is_default, :show_on_page, :sort_order, :user)',
                    $params
                );
            }
        });
    }

    public function delete(string $pageKey, string $slug): bool
    {
        $this->ensureSchema();

        return $this->executeWithRowCount(
            'DELETE FROM `' . self::TABLE . '` WHERE page_key = :page AND slug = :slug',
            [':page' => $pageKey, ':slug' => $slug]
        ) > 0;
    }

    private function exists(string $pageKey, string $slug): bool
    {
        return $this->fetchScalar(
            'SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE page_key = :page AND slug = :slug',
            [':page' => $pageKey, ':slug' => $slug]
        ) > 0;
    }

    private function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }
        $this->schemaEnsured = true;

        // DDL adaptée au driver : MySQL en prod, SQLite pour la suite Unit et le
        // repli `php -S` (ni AUTO_INCREMENT, ni ON UPDATE, ni options de table).
        $isMysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        if ($isMysql) {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
                . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `page_key` VARCHAR(32) NOT NULL,'
                . ' `slug` VARCHAR(40) NOT NULL,'
                . ' `label` VARCHAR(64) NOT NULL,'
                . ' `description` VARCHAR(255) DEFAULT NULL,'
                . ' `layout_json` TEXT NOT NULL,'
                . ' `is_default` TINYINT(1) NOT NULL DEFAULT 0,'
                . ' `show_on_page` TINYINT(1) NOT NULL DEFAULT 1,'
                . ' `sort_order` INT NOT NULL DEFAULT 100,'
                . ' `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
                . ' `updated_by` VARCHAR(64) DEFAULT NULL,'
                . ' PRIMARY KEY (`id`),'
                . ' UNIQUE KEY `uniq_page_slug` (`page_key`, `slug`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . ' `id` INTEGER PRIMARY KEY AUTOINCREMENT,'
            . ' `page_key` VARCHAR(32) NOT NULL,'
            . ' `slug` VARCHAR(40) NOT NULL,'
            . ' `label` VARCHAR(64) NOT NULL,'
            . ' `description` VARCHAR(255) DEFAULT NULL,'
            . ' `layout_json` TEXT NOT NULL,'
            . ' `is_default` TINYINT(1) NOT NULL DEFAULT 0,'
            . ' `show_on_page` TINYINT(1) NOT NULL DEFAULT 1,'
            . ' `sort_order` INT NOT NULL DEFAULT 100,'
            . ' `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . ' `updated_by` VARCHAR(64) DEFAULT NULL,'
            . ' UNIQUE (`page_key`, `slug`)'
            . ')'
        );
    }
}
